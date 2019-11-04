<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Writer;

use Keboola\Csv\CsvFile;
use Keboola\DbWriter\Exception\UserException;
use Keboola\DbWriter\Writer;
use Keboola\DbWriter\WriterInterface;

class Common extends Writer implements WriterInterface
{
    /** @var array */
    protected static $allowedTypes = [
        'int', 'smallint', 'bigint',
        'decimal', 'float', 'double',
        'date', 'datetime', 'timestamp',
        'char', 'varchar', 'text', 'blob',
    ];

    /** @var \PDO */
    protected $db;

    /**
     * @param array $dbParams
     * @return mixed
     * @throws UserException
     */
    public function createConnection(array $dbParams)
    {
        // convert errors to PDOExceptions
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::MYSQL_ATTR_LOCAL_INFILE => true,
        ];

        // check params
        foreach (['host', 'database', 'user', 'password'] as $r) {
            if (!isset($dbParams[$r])) {
                throw new UserException(sprintf("Parameter %s is missing.", $r));
            }
        }

        $port = isset($dbParams['port']) ? $dbParams['port'] : '3306';
        $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8", $dbParams['host'], $port, $dbParams['database']);

        $pdo = new \PDO($dsn, $dbParams['user'], $dbParams['password'], $options);
        $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $pdo->exec("SET NAMES utf8;");

        return $pdo;
    }

    public function generateTmpName(string $tableName): string
    {
        $tmpId = '_temp_' . uniqid();
        return mb_substr($tableName, 0, 64 - mb_strlen($tmpId)) . $tmpId;
    }

    public function drop(string $tableName): void
    {
        $this->db->exec("DROP TABLE IF EXISTS `{$tableName}`;");
    }

    public function create(array $table): void
    {
        $sql = sprintf(
            "CREATE %s TABLE `%s` (",
            isset($table['temporary']) && $table['temporary'] === true ? 'TEMPORARY' : '',
            $table['dbName']
        );

        $columns = $table['items'];
        foreach ($columns as $k => $col) {
            $type = strtoupper($col['type']);
            if ($type == 'IGNORE') {
                continue;
            }

            if (!empty($col['size'])) {
                $type .= "({$col['size']})";
            }

            $null = $col['nullable'] ? 'NULL' : 'NOT NULL';

            $default = empty($col['default']) ? '' : $col['default'];
            if ($type == 'TEXT') {
                $default = '';
            }

            $sql .= "`{$col['dbName']}` $type $null $default";
            $sql .= ',';
        }

        $sql = substr($sql, 0, -1);
        $sql .= ") DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";

        $this->db->exec($sql);
    }

    public function write(CsvFile $csvFile, array $table): void
    {
        $query = "
            LOAD DATA LOCAL INFILE '{$csvFile->getPathname()}'
            INTO TABLE `{$table['dbName']}`
            CHARACTER SET utf8
            FIELDS TERMINATED BY ','
            OPTIONALLY ENCLOSED BY '\"'
            ESCAPED BY ''
            IGNORE 1 LINES
        ";

        try {
            $this->db->exec($query);
        } catch (\PDOException $e) {
            throw new UserException("Query failed: " . $e->getMessage(), 400, $e, [
                'query' => $query,
            ]);
        }
    }

    public function upsert(array $table, string $targetTable): void
    {
        $sourceTable = $table['dbName'];

        // create target table if not exists
        if (!$this->tableExists($targetTable)) {
            $destinationTable = $table;
            $destinationTable['dbName'] = $targetTable;
            $this->create($destinationTable);
        }

        $columns = array_map(function ($item) {
            return $item['dbName'];
        }, $table['items']);

        if (!empty($table['primaryKey'])) {
            // update data
            $joinClauseArr = [];
            foreach ($table['primaryKey'] as $index => $value) {
                $joinClauseArr[] = "a.`{$value}`=b.`{$value}`";
            }
            $joinClause = implode(' AND ', $joinClauseArr);

            $valuesClauseArr = [];
            foreach ($columns as $index => $column) {
                $valuesClauseArr[] = "a.`{$column}`=b.`{$column}`";
            }
            $valuesClause = implode(',', $valuesClauseArr);

            $query = "UPDATE `{$targetTable}` a
                INNER JOIN `{$sourceTable}` b ON {$joinClause}
                SET {$valuesClause}
            ";
            $this->db->exec($query);

            // delete updated from temp table
            $query = "DELETE a.* FROM `{$sourceTable}` a
                INNER JOIN `{$targetTable}` b ON {$joinClause}
            ";
            $this->db->exec($query);
        }

        // insert new data
        $columnsClause = implode(',', $columns);
        $query = "INSERT INTO `{$targetTable}` ({$columnsClause}) SELECT * FROM `{$sourceTable}`";
        $this->db->exec($query);
    }

    public function tableExists(string $tableName): bool
    {
        $tableArr = explode('.', $tableName);
        $tableName = isset($tableArr[1])?$tableArr[1]:$tableArr[0];
        $tableName = str_replace(['[',']'], '', $tableName);
        $stmt = $this->db->query(sprintf("SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = '%s'", $tableName));
        $res = $stmt->fetchAll();
        return !empty($res);
    }

    protected function getPlaceholders(array $row): string
    {
        $result = [];
        foreach ($row as $r) {
            $result[] = '?';
        }

        return implode(',', $result);
    }

    public static function isTypeValid(string $type): bool
    {
        return in_array(strtolower($type), static::$allowedTypes);
    }

    public static function getAllowedTypes(): array
    {
        return static::$allowedTypes;
    }

    public function showTables(string $dbName): array
    {
        $stmt = $this->db->query("SHOW TABLES");
        $res = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(function ($item) {
            return array_shift($item);
        }, $res);
    }

    public function getTableInfo(string $tableName): array
    {
        $stmt = $this->db->query("DESCRIBE {$tableName}");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function validateTable(array $tableConfig): void
    {
        $tableInfo = $this->getTableInfo($tableConfig['dbName']);

        foreach ($tableConfig['items'] as $column) {
            $exists = false;
            $dstDataType = null;
            foreach ($tableInfo as $dbColumn) {
                $exists = ($dbColumn['Field'] == $column['dbName']);
                if ($exists) {
                    $dstDataType = preg_replace('/\(.*\)/', '', $dbColumn['Type']);
                    break;
                }
            }

            if (!$exists) {
                throw new UserException(sprintf(
                    'Column \'%s\' not found in destination table \'%s\'',
                    $column['dbName'],
                    $tableConfig['dbName']
                ));
            }

            $srcDataType = strtolower($column['type']);
            if ($dstDataType !== $srcDataType) {
                throw new UserException(sprintf(
                    'Data type mismatch. Column \'%s\' is of type \'%s\' in writer, but is \'%s\' in destination table \'%s\'',
                    $column['dbName'],
                    $srcDataType,
                    $dstDataType,
                    $tableConfig['dbName']
                ));
            }
        }
    }
}
