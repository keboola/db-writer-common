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

class CommonTest extends BaseTest
{
    const DRIVER = 'common';

    public function setUp()
    {
        parent::setUp();

        $config = $this->getConfig(self::DRIVER);
        $writer = $this->getWriter($config['parameters']);
        $conn = $writer->getConnection();

        $tables = $config['parameters']['tables'];

        foreach ($tables as $table) {
            $conn->exec("DROP TABLE IF EXISTS " . $table['dbName']);
        }
    }

    public function testDrop()
    {
        $writer = $this->getWriter(self::DRIVER);
        $config = $this->getConfig(self::DRIVER);
        $conn = $writer->getConnection();
        $conn->exec("CREATE TABLE dropMe (
          id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          firstname VARCHAR(30) NOT NULL,
          lastname VARCHAR(30) NOT NULL)");

        $writer->drop("dropMe");

        $stmt = $conn->query(sprintf("SHOW TABLES IN `%s`", $config['parameters']['db']['database']));
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
        $writer = $this->getWriter(self::DRIVER);
        $config = $this->getConfig(self::DRIVER);
        $tables = $config['parameters']['tables'];

        foreach ($tables as $table) {
            $writer->create($table);
        }

        /** @var \PDO $conn */
        $conn = $writer->getConnection();
        $stmt = $conn->query(sprintf("SHOW TABLES IN `%s`", $config['parameters']['db']['database']));
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
        $writer = $this->getWriter(self::DRIVER);
        $config = $this->getConfig(self::DRIVER);
        $tables = $config['parameters']['tables'];

        $table = $tables[0];
        $sourceTableId = $table['tableId'];
        $outputTableName = $table['dbName'];
        $sourceFilename = $this->dataDir . "/" . self::DRIVER . "/in/tables/" . $sourceTableId . ".csv";

        $writer->create($table);
        $writer->write($sourceFilename, $outputTableName, $table);

        $conn = $writer->getConnection();
        $stmt = $conn->query("SELECT * FROM encoding");
        $res = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $resFilename = tempnam('/tmp', 'db-wr-test-tmp');
        $csv = new CsvFile($resFilename);
        $csv->writeRow(["col1","col2"]);
        foreach ($res as $row) {
            $csv->writeRow($row);
        }

        $this->assertFileEquals($sourceFilename, $resFilename);
    }

    public function testGetAllowedTypes()
    {
        $writer = $this->getWriter(self::DRIVER);
        $allowedTypes = $writer->getAllowedTypes();

        $this->assertEquals([
            'int', 'smallint', 'bigint',
            'decimal', 'float', 'double',
            'date', 'datetime', 'timestamp',
            'char', 'varchar', 'text', 'blob'
        ], $allowedTypes);
    }
}
