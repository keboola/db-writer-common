<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Tests;

use Keboola\Csv\CsvWriter;
use Keboola\DbWriter\Exception\UserException;
use SplFileInfo;
use Keboola\DbWriter\Application;
use Keboola\DbWriter\Configuration\ConfigDefinition;
use Keboola\DbWriter\Configuration\Validator;
use Keboola\DbWriter\Test\BaseTest;
use Psr\Log\LoggerInterface;
use Psr\Log\Test\TestLogger;

class ApplicationTest extends BaseTest
{
    /** @var array */
    private $config;

    /** @var TestLogger */
    private $logger;

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

    public function testRun(): void
    {
        $this->runApplication($this->getApp($this->config));
    }

    public function testCheckHostname(): void
    {
        $config = $this->config;
        $config['image_parameters']['approvedHostnames'] = [
            [
                'host' => $this->getEnv('DB_HOST'),
                'port' => $this->getEnv('DB_PORT'),
            ],
        ];

        $this->runApplication($this->getApp($config, $this->logger));
    }

    public function testCheckHostnameFailed(): void
    {
        $config = $this->config;
        $config['image_parameters']['approvedHostnames'] = [
            [
                'host' => 'InvalidHostname',
                'port' => 12344,
            ],
        ];

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Hostname "mysql" with port "3306" is not approved.');
        $this->getApp($config, $this->logger)->run();
    }

    public function testCheckHostnameFailedEmptyArray(): void
    {
        $config = $this->config;
        $config['image_parameters']['approvedHostnames'] = [];

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Hostname "mysql" with port "3306" is not approved.');
        $this->getApp($config, $this->logger)->run();
    }

    public function testRunWithSSH(): void
    {
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

        $this->runApplication($this->getApp($config, $this->logger));

        $this->assertCount(1, $this->logger->records);
        $this->assertTrue($this->logger->hasInfoThatContains('Creating SSH tunnel'));
    }

    public function testRunWithSSHException(): void
    {
        $this->expectException('Keboola\DbWriter\Exception\UserException');
        $this->expectExceptionMessage('Could not resolve hostname herebedragons');

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

    protected function getApp(array $config, ?LoggerInterface $logger = null): Application
    {
        return new Application($config, $logger ?: new TestLogger());
    }

    protected function runApplication(Application $app): void
    {
        $result = $app->run();
        $this->assertEquals('Writer finished successfully', $result);

        $encodingIn = $this->dataDir . '/in/tables/encoding.csv';
        $encodingOut = $this->dbTableToCsv($app['writer']->getConnection(), 'encoding', ['col1', 'col2']);

        $this->assertFileExists($encodingOut->getPathname());
        $this->assertEquals(file_get_contents($encodingIn), file_get_contents($encodingOut->getPathname()));
    }

    protected function dbTableToCsv(\PDO $conn, string $tableName, array $header): SplFileInfo
    {
        $stmt = $conn->query("SELECT * FROM {$tableName}");
        $res = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $path = tempnam('/tmp', 'db-wr-test-tmp');
        $csv = new CsvWriter($path);
        $csv->writeRow($header);
        foreach ($res as $row) {
            $csv->writeRow($row);
        }

        return new SplFileInfo($path);
    }
}
