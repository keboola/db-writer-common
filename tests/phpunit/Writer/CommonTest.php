<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Tests\Writer;

use Keboola\Csv\CsvFile;
use Keboola\DbWriter\Configuration\ConfigDefinition;
use Keboola\DbWriter\Configuration\Validator;
use Keboola\DbWriter\Test\BaseTest;
use Keboola\DbWriter\WriterInterface;

class CommonTest extends BaseTest
{
    /** @var WriterInterface */
    protected $writer;

    /** @var array */
    protected $config;

    public function setUp(): void
    {
        parent::setUp();

        $validate = Validator::getValidator(new ConfigDefinition());
        $this->config['parameters'] = $validate($this->getConfig()['parameters']);
        $this->writer = $this->getWriter($this->config['parameters']);
        $conn = $this->writer->getConnection();

        $tables = $this->writer->showTables($this->config['parameters']['db']['database']);

        foreach ($tables as $tableName) {
            $conn->exec("DROP TABLE IF EXISTS {$tableName}");
        }
    }

    public function testDrop(): void
    {
        $conn = $this->writer->getConnection();
        $conn->exec("CREATE TABLE dropMe (
          id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          firstname VARCHAR(30) NOT NULL,
          lastname VARCHAR(30) NOT NULL)");

        $this->writer->drop("dropMe");

        $stmt = $conn->query(sprintf("SHOW TABLES IN `%s`", $this->config['parameters']['db']['database']));
        $res = $stmt->fetchAll();

        $tableExists = false;
        foreach ($res as $r) {
            if ($r[0] == "dropMe") {
                $tableExists = true;
                break;
            }
        }

        $this->assertFalse($tableExists);
    }

    public function testCreate(): void
    {
        $tables = $this->config['parameters']['tables'];

        foreach ($tables as $table) {
            $this->writer->create($table);
        }

        /** @var \PDO $conn */
        $conn = $this->writer->getConnection();
        $stmt = $conn->query(sprintf("SHOW TABLES IN `%s`", $this->config['parameters']['db']['database']));
        $res = $stmt->fetchAll();

        $tableExists = false;

        foreach ($res as $r) {
            if ($r[0] == $tables[0]['dbName']) {
                $tableExists = true;
                break;
            }
        }

        $this->assertTrue($tableExists);
    }

    public function testWrite(): void
    {
        $tables = $this->config['parameters']['tables'];

        $table = $tables[0];
        $sourceFilename = $this->dataDir . "/in/tables/" . $table['tableId'] . ".csv";
        $table['dbName'] .= $table['incremental']?'_temp_' . uniqid():'';

        $this->writer->create($table);
        $this->writer->write(new CsvFile($sourceFilename), $table);

        $conn = $this->writer->getConnection();
        $stmt = $conn->query("SELECT * FROM {$table['dbName']}");
        $res = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $resFilename = tempnam('/tmp', 'db-wr-test-tmp');
        $csv = new CsvFile($resFilename);
        $csv->writeRow(["col1", "col2"]);
        foreach ($res as $row) {
            $csv->writeRow($row);
        }

        $this->assertFileEquals($sourceFilename, $resFilename);
    }

    public function testUpsert(): void
    {
        $conn = $this->writer->getConnection();
        $tables = $this->config['parameters']['tables'];

        $table = $tables[1];
        $sourceFilename = $this->dataDir . "/in/tables/" . $table['tableId'] . ".csv";
        $targetTable = $table;
        $table['dbName'] .= $table['incremental']?'_temp_' . uniqid():'';

        // first write
        $this->writer->create($targetTable);
        $this->writer->write(new CsvFile($sourceFilename), $targetTable);

        // second write
        $sourceFilename = $this->dataDir . "/in/tables/" . $table['tableId'] . "_increment.csv";
        $this->writer->create($table);
        $this->writer->write(new CsvFile($sourceFilename), $table);
        $this->writer->upsert($table, $targetTable['dbName']);

        $stmt = $conn->query("SELECT * FROM {$targetTable['dbName']}");
        $res = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $resFilename = tempnam('/tmp', 'db-wr-test-tmp');
        $csv = new CsvFile($resFilename);
        $csv->writeRow(["id", "name", "glasses"]);
        foreach ($res as $row) {
            $csv->writeRow($row);
        }

        $expectedFilename = $this->dataDir . "/in/tables/" . $table['tableId'] . "_merged.csv";

        $this->assertFileEquals($expectedFilename, $resFilename);
    }

    public function testGetAllowedTypes(): void
    {
        $allowedTypes = $this->writer->getAllowedTypes();

        $this->assertEquals([
            'int', 'smallint', 'bigint',
            'decimal', 'float', 'double',
            'date', 'datetime', 'timestamp',
            'char', 'varchar', 'text', 'blob',
        ], $allowedTypes);
    }

    public function testShowTables(): void
    {
        foreach ($this->config['parameters']['tables'] as $table) {
            $this->writer->create($table);
        }
        $tables = $this->writer->showTables($this->config['parameters']['db']['database']);

        foreach ($this->config['parameters']['tables'] as $table) {
            $this->assertContains($table['dbName'], $tables);
        }
    }

    public function testGetTableInfo(): void
    {
        $table = $this->config['parameters']['tables'][0];
        $this->writer->create($table);

        $tableInfo = $this->writer->getTableInfo($table['dbName']);

        $this->assertEquals('col1', $tableInfo[0]['Field']);
        $this->assertEquals('varchar(255)', $tableInfo[0]['Type']);
    }

    public function testGenerateTmpName(): void
    {
        $tableName = 'firstTable';

        $tmpName = $this->writer->generateTmpName($tableName);
        $this->assertRegExp('/' . $tableName . '/ui', $tmpName);
        $this->assertRegExp('/temp/ui', $tmpName);
        $this->assertLessThanOrEqual(64, mb_strlen($tmpName));

        $tableName = str_repeat('firstTableWithLongName', 6);

        $tmpName = $this->writer->generateTmpName($tableName);
        $this->assertRegExp('/temp/ui', $tmpName);
        $this->assertLessThanOrEqual(64, mb_strlen($tmpName));
    }

    public function testValidateTable(): void
    {
        $table = $this->config['parameters']['tables'][0];
        $this->writer->create($table);

        $this->writer->validateTable($table);

        $this->assertTrue(true);
    }
}
