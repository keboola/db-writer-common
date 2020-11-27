<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Tests;

use Keboola\Csv\CsvFile;
use Keboola\DbWriter\Application;
use Keboola\DbWriter\Configuration\ConfigDefinition;
use Keboola\DbWriter\Configuration\Validator;
use Keboola\DbWriter\Exception\UserException;
use Keboola\DbWriter\Logger;
use Keboola\DbWriter\Test\BaseTest;
use Monolog\Handler\TestHandler;

class ApplicationTest extends BaseTest
{
    /** @var array */
    private $config;

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
    }

    public function testRun(): void
    {
        $this->runApplication($this->getApp($this->config));
    }

    public function testCheckHostname(): void
    {
        $testHandler = new TestHandler();

        $logger = new Logger($this->appName);
        $logger->setHandlers([$testHandler]);

        $config = $this->config;
        $config['image_parameters']['approveHostnames'] = [
            [
                'host' => $this->getEnv('DB_HOST'),
                'port' => $this->getEnv('DB_PORT'),
            ],
        ];

        $this->runApplication($this->getApp($config, $logger));
    }

    public function testCheckHostnameFailed(): void
    {
        $testHandler = new TestHandler();

        $logger = new Logger($this->appName);
        $logger->setHandlers([$testHandler]);

        $config = $this->config;
        $config['image_parameters']['approveHostnames'] = [
            [
                'host' => 'InvalidHostname',
                'port' => 12344,
            ],
        ];

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Hostname "mysql" with port "3306" is not approved.');
        $this->getApp($config, $logger)->run();
    }

    public function testRunWithSSH(): void
    {
        $testHandler = new TestHandler();

        $logger = new Logger($this->appName);
        $logger->setHandlers([$testHandler]);

        $config = $this->config;
        $config['parameters']['db']['ssh'] = [
            'enabled' => true,
            'keys' => [
                '#private' => $this->getPrivateKey(),
                'public' => $this->getPublicKey(),
            ],
            'sshHost' => 'sshproxy',
            'localPort' => '33306',
            'remoteHost' => 'mysql',
            'remotePort' => '3306',
        ];

        $this->runApplication($this->getApp($config, $logger));

        $records = $testHandler->getRecords();
        $record = reset($records);

        $this->assertCount(1, $testHandler->getRecords());

        $this->assertArrayHasKey('message', $record);
        $this->assertArrayHasKey('level', $record);

        $this->assertEquals(Logger::INFO, $record['level']);
        $this->assertRegExp('/Creating SSH tunnel/ui', $record['message']);
    }

    public function testRunWithSSHException(): void
    {
        $this->expectException('Keboola\DbWriter\Exception\UserException');
        $this->expectExceptionMessageRegExp('/Could not resolve hostname herebedragons/ui');

        $config = $this->config;
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

    public function testRunReorderColumns(): void
    {
        $simpleTableCfg = $this->config['parameters']['tables'][1];
        $firstCol = $simpleTableCfg['items'][0];
        $secondCol = $simpleTableCfg['items'][1];
        $simpleTableCfg['items'][0] = $secondCol;
        $simpleTableCfg['items'][1] = $firstCol;
        $this->config['parameters']['tables'][1] = $simpleTableCfg;

        $this->runApplication($this->getApp($this->config));
    }

    public function testGetTablesInfo(): void
    {
        $this->runApplication($this->getApp($this->config));

        $config = $this->config;
        $config['action'] = 'getTablesInfo';
        $result = $this->getApp($config)->run();
        $resultJson = json_decode($result, true);

        $this->assertContains('encoding', array_keys($resultJson['tables']));
    }

    protected function getApp(array $config, ?Logger $logger = null): Application
    {
        return new Application($config, $logger ?: new Logger($this->appName));
    }

    protected function runApplication(Application $app): void
    {
        $result = $app->run();

        $encodingIn = $this->dataDir . '/in/tables/encoding.csv';
        $encodingOut = $this->dbTableToCsv($app['writer']->getConnection(), 'encoding', ['col1', 'col2']);

        $this->assertEquals('Writer finished successfully', $result);
        $this->assertFileExists($encodingOut->getPathname());
        $this->assertEquals(file_get_contents($encodingIn), file_get_contents($encodingOut->getPathname()));
    }

    protected function dbTableToCsv(\PDO $conn, string $tableName, array $header): CsvFile
    {
        $stmt = $conn->query("SELECT * FROM {$tableName}");
        $res = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $resFilename = tempnam('/tmp', 'db-wr-test-tmp');
        $csv = new CsvFile($resFilename);
        $csv->writeRow($header);
        foreach ($res as $row) {
            $csv->writeRow($row);
        }

        return $csv;
    }
}
