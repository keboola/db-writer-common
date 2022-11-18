<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Tests;

use Keboola\Csv\CsvWriter;
use Keboola\DbWriter\Application;
use Keboola\DbWriter\Configuration\ConfigDefinition;
use Keboola\DbWriter\Configuration\Validator;
use Keboola\DbWriter\Exception\UserException;
use Keboola\DbWriter\Test\BaseTest;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\Test\TestLogger;
use SplFileInfo;

class ConfigRowApplicationTest extends BaseTest
{
    /** @var array */
    private array $config;

    private TestLogger $logger;

    public function setUp(): void
    {
        parent::setUp();
        $validate = Validator::getValidator(new ConfigDefinition());
        $this->config = $this->getConfig();
        $this->config['parameters'] = $validate($this->config['parameters']);

        $writer = $this->getWriter($this->config['parameters']);
        $conn = $writer->getConnection();
        $tables = $writer->showTables($this->config['parameters']['db']['database']);

        foreach ($tables as $tableName) {
            $conn->exec("DROP TABLE IF EXISTS {$tableName}");
        }

        $this->logger = new TestLogger();
    }

    public function dataDirProvider(): array
    {
        return [
            [
                'encoding'=> [
                    'datadir' => __DIR__ . '/../data/encoding',
                    'inputFile' => 'encoding.csv',
                    'outputTable' => 'encoding',
                    'header' => ['col1', 'col2'],
                ],
            ], [
                'simple'=> [
                    'datadir' => __DIR__ . '/../data/simple',
                    'inputFile' => 'simple.csv',
                    'outputTable' => 'simple',
                    'header' => ['id', 'name', 'glasses'],
                ],
            ],
        ];
    }

    /**
     * @dataProvider dataDirProvider
     */
    public function testRun(array $dataDefinition): void
    {
        $result = $this->runApplication(
            $this->getApp($this->getConfig($dataDefinition['datadir'])),
            $dataDefinition['inputFile'],
            $dataDefinition['outputTable'],
            $dataDefinition['header']
        );
    }

    public function testRunWithSSH(): void
    {
        $datadir = __DIR__ . '/../data/encoding';

        $config = $this->getConfig($datadir);
        $config['parameters']['db']['ssh'] = [
            'enabled' => true,
            'keys' => [
                '#private' => $this->getPrivateKey(),
                'public' => $this->getPublicKey(),
            ],
            'sshHost' => 'sshproxy',
            'localPort' => '33307',
            'remoteHost' => 'mysql',
            'remotePort' => '3306',
        ];

        $this->runApplication(
            $this->getApp($config, $this->logger),
            'encoding.csv',
            'encoding',
            ['col1', 'col2']
        );

        $this->assertCount(1, $this->logger->records);
        $this->assertTrue($this->logger->hasInfoThatContains('Creating SSH tunnel'));
    }

    /**
     * @dataProvider dataDirProvider
     */
    public function testRunWithSSHException(array $dataDefinition): void
    {
        $this->expectException('Keboola\DbWriter\Exception\UserException');
        $this->expectExceptionMessage('Could not resolve hostname herebedragons');

        $config = $this->getConfig($dataDefinition['datadir']);
        $config['parameters']['db']['ssh'] = [
            'enabled' => true,
            'keys' => [
                '#private' => $this->getPrivateKey(),
                'public' => $this->getPublicKey(),
            ],
            'sshHost' => 'hereBeDragons',
            'localPort' => '33306',
            'remoteHost' => 'mysql',
            'remotePort' => '3306',
        ];

        $this->getApp($config)->run();
    }

    /**
     * @dataProvider dataDirProvider
     * @param array $dataDefinition
     */
    public function testRunReorderColumns(array $dataDefinition): void
    {
        $this->config = $this->getConfig($dataDefinition['datadir']);
        $configParams = $this->config['parameters'];
        $firstCol = $configParams['items'][0];
        $secondCol = $configParams['items'][1];
        $configParams['items'][0] = $secondCol;
        $configParams['items'][1] = $firstCol;
        $this->config['parameters'] = $configParams;

        $this->runApplication(
            $this->getApp($this->config),
            $dataDefinition['inputFile'],
            $dataDefinition['outputTable'],
            $dataDefinition['header']
        );
    }

    /**
     * @dataProvider dataDirProvider
     * @param array $dataDefinition
     */
    public function testGetTablesInfo(array $dataDefinition): void
    {
        $this->runApplication(
            $this->getApp($this->config),
            $dataDefinition['inputFile'],
            $dataDefinition['outputTable'],
            $dataDefinition['header']
        );

        $config = $this->config;
        $config['action'] = 'getTablesInfo';
        $result = $this->getApp($config)->run();
        $resultJson = json_decode($result, true);

        $this->assertContains('encoding', array_keys($resultJson['tables']));
    }

    public function testInvalidInputMapping(): void
    {
        $config = $this->getConfig(__DIR__ . '/../data/simple');
        $config['parameters']['tableId'] = 'invalidtable';

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Table "invalidtable" in storage input mapping cannot be found.');
        $this->runApplication(
            $this->getApp($config),
            'encoding.csv',
            'encoding',
            ['col1', 'col2']
        );
    }

    protected function getApp(array $config, ?LoggerInterface $logger = null): Application
    {
        return new Application($config, $logger ?: new TestLogger());
    }

    protected function runApplication(Application $app, String $inputFile, String $outputTable, array $header): string
    {
        $result = $app->run();

        $encodingIn = $this->dataDir . '/in/tables/' . $inputFile;
        $encodingOut = $this->dbTableToCsv($app['writer']->getConnection(), $outputTable, $header);

        $this->assertEquals('Writer finished successfully', $result);
        $this->assertFileExists($encodingOut->getPathname());
        $this->assertEquals(file_get_contents($encodingIn), file_get_contents($encodingOut->getPathname()));
        return $result;
    }

    protected function dbTableToCsv(PDO $conn, string $tableName, array $header): SplFileInfo
    {
        $stmt = $conn->query("SELECT * FROM {$tableName}");
        $res = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $path = tempnam('/tmp', 'db-wr-test-tmp');
        $csv = new CsvWriter($path);
        $csv->writeRow($header);
        foreach ($res as $row) {
            $csv->writeRow($row);
        }
        return new SplFileInfo($path);
    }
}
