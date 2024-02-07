<?php

declare(strict_types=1);

namespace Keboola\DbWriter\TestsFunctional;

use Keboola\Csv\CsvWriter;
use Keboola\Csv\Exception;
use Keboola\Csv\InvalidArgumentException;
use Keboola\DatadirTests\DatadirTestCase;
use Keboola\DatadirTests\DatadirTestSpecificationInterface;
use Keboola\DbWriter\TestsTraits\CloseSshTunnelsTrait;
use Keboola\DbWriter\TestsTraits\DropAllTablesTrait;
use Keboola\DbWriterAdapter\PDO\PdoConnection;
use RuntimeException;

class DatadirTest extends DatadirTestCase
{
    use CloseSshTunnelsTrait;
    use DropAllTablesTrait;

    public PdoConnection $connection;

    protected string $testProjectDir;

    protected string $testTempDir;

    protected function getScript(): string
    {
        return $this->getTestFileDir() . '/../../tests/Fixtures/run.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        putenv('KBC_COMPONENT_RUN_MODE=run');

        // Test dir, eg. "/code/tests/functional/full-load-ok"
        $this->testProjectDir = $this->getTestFileDir() . '/' . $this->dataName();
        $this->testTempDir = $this->temp->getTmpFolder();

        $isSsl = str_starts_with((string) $this->dataName(), 'ssl-');
        $this->connection = PdoTestConnection::createConnection();
        $this->closeSshTunnels();
        $this->dropAllTables($this->connection);

        // Load setUp.php file - used to init database state
        $setUpPhpFile = $this->testProjectDir . '/setUp.php';
        if (file_exists($setUpPhpFile)) {
            // Get callback from file and check it
            $initCallback = require $setUpPhpFile;
            if (!is_callable($initCallback)) {
                throw new RuntimeException(sprintf('File "%s" must return callback!', $setUpPhpFile));
            }

            // Invoke callback
            $initCallback($this);
        }
    }

    /**
     * @dataProvider provideDatadirSpecifications
     */
    public function testDatadir(DatadirTestSpecificationInterface $specification): void
    {
        $tempDatadir = $this->getTempDatadir($specification);

        $process = $this->runScript($tempDatadir->getTmpFolder());

        $this->exportTablesData($tempDatadir->getTmpFolder());

        $this->assertMatchesSpecification($specification, $process, $tempDatadir->getTmpFolder());
    }

    protected function modifyConfigJsonContent(string $content): string
    {
        $content = parent::modifyConfigJsonContent($content);
        /** @var array<array> $config */
        $config = (array) json_decode($content, true);
        $config['parameters']['data_dir'] = $this->temp->getTmpFolder();
        return (string) json_encode($config);
    }

    /**
     * @throws Exception
     * @throws InvalidArgumentException
     */
    protected function exportTablesData(string $testTempDir): void
    {
        $sqlTables = <<<SQL
SELECT `table_schema`,`table_name`
FROM information_schema.tables 
WHERE TABLE_SCHEMA NOT IN ("performance_schema", "mysql", "information_schema", "sys");
SQL;

        /** @var array<array> $tables */
        $tables = $this->connection->fetchAll($sqlTables, 3);

        foreach ($tables as $table) {
            $sql = sprintf('SELECT * FROM `%s`.`%s`', $table['table_schema'], $table['table_name']);
            $data = $this->connection->fetchAll($sql, 3);
            $csv = new CsvWriter(sprintf('%s/out/tables/%s.csv', $testTempDir, $table['table_name']));
            foreach ($data as $item) {
                $csv->writeRow($item);
            }
        }
    }
}
