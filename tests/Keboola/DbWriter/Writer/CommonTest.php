<?php
/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 05/11/15
 * Time: 13:38
 */

namespace Keboola\DbWriter\Writer;

use Keboola\Csv\CsvFile;
use Keboola\DbWriter\Test\BaseTest;
use Keboola\DbWriter\WriterInterface;

class CommonTest extends BaseTest
{
    const DRIVER = 'common';

    /** @var WriterInterface */
    protected $writer;

    protected $config;

    public function setUp()
    {
        parent::setUp();

        $this->config = $this->getConfig(self::DRIVER);
        $this->writer = $this->getWriter($this->config['parameters']);
        $conn = $this->writer->getConnection();

        $tables = $this->config['parameters']['tables'];

        foreach ($tables as $table) {
            $conn->exec("DROP TABLE IF EXISTS " . $table['dbName']);
            $conn->exec("DROP TABLE IF EXISTS " . $table['dbName'] . "_temp");
        }
    }

    public function testDrop()
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

    public function testCreate()
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

    public function testWrite()
    {
        $tables = $this->config['parameters']['tables'];

        $table = $tables[0];
        $sourceFilename = $this->dataDir . "/" . $table['tableId'] . ".csv";
        $table['dbName'] .= $table['incremental']?'_temp_' . uniqid():'';

        $this->writer->create($table);
        $this->writer->write($sourceFilename, $table);

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

    public function testUpsert()
    {
        $conn = $this->writer->getConnection();
        $tables = $this->config['parameters']['tables'];

        $table = $tables[1];
        $sourceFilename = $this->dataDir . "/" . $table['tableId'] . ".csv";
        $targetTable = $table;
        $table['dbName'] .= $table['incremental']?'_temp_' . uniqid():'';

        // first write
        $this->writer->create($targetTable);
        $this->writer->write($sourceFilename, $targetTable);

        // second write
        $sourceFilename = $this->dataDir . "/" . $table['tableId'] . "_increment.csv";
        $this->writer->create($table);
        $this->writer->write($sourceFilename, $table);
        $this->writer->upsert($table, $targetTable['dbName']);

        $stmt = $conn->query("SELECT * FROM {$targetTable['dbName']}");
        $res = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $resFilename = tempnam('/tmp', 'db-wr-test-tmp');
        $csv = new CsvFile($resFilename);
        $csv->writeRow(["id", "name", "glasses"]);
        foreach ($res as $row) {
            $csv->writeRow($row);
        }

        $expectedFilename = $this->dataDir . "/" . $table['tableId'] . "_merged.csv";

        $this->assertFileEquals($expectedFilename, $resFilename);
    }

    public function testGetAllowedTypes()
    {
        $allowedTypes = $this->writer->getAllowedTypes();

        $this->assertEquals([
            'int', 'smallint', 'bigint',
            'decimal', 'float', 'double',
            'date', 'datetime', 'timestamp',
            'char', 'varchar', 'text', 'blob'
        ], $allowedTypes);
    }
}
