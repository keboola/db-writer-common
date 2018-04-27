<?php

namespace Keboola\DbWriter\Tests;

use Keboola\Csv\CsvFile;
use Keboola\DbWriter\Application;
use Keboola\DbWriter\Configuration\ConfigDefinition;
use Keboola\DbWriter\Configuration\Validator;
use Keboola\DbWriter\Logger;
use Keboola\DbWriter\Test\BaseTest;
use Monolog\Handler\TestHandler;

class ApplicationTest extends BaseTest
{
    private $config;

    public function setUp()
    {
        parent::setUp();
        $validate = Validator::getValidator(new ConfigDefinition());
        $config = $this->getConfig();
        $config['parameters'] = $validate($config['parameters']);
        $this->config = $config;

        $writer = $this->getWriter($this->config['parameters']);
        $conn = $writer->getConnection();
        $tables = $writer->showTables($this->config['parameters']['db']['database']);

        foreach ($tables as $tableName) {
            $conn->exec("DROP TABLE IF EXISTS {$tableName}");
        }
    }

    public function testRun()
    {
        $this->runApp($this->getApp($this->config));
    }

    public function testRunWithSSH()
    {
        $testHandler = new TestHandler();

        $logger = new Logger($this->appName);
        $logger->setHandlers([$testHandler]);

        $config = $this->config;
        $config['parameters']['db']['ssh'] = [
            'enabled' => true,
            'keys' => [
                '#private' => $this->getPrivateKey(),
                'public' => $this->getEnv('SSH_KEY_PUBLIC')
            ],
            'sshHost' => 'sshproxy',
            'localPort' => '33306',
            'remoteHost' => 'mysql',
            'remotePort' => '3306',
        ];

        $this->runApp($this->getApp($config, $logger));

        $records = $testHandler->getRecords();
        $record = reset($records);

        $this->assertCount(1, $testHandler->getRecords());

        $this->assertArrayHasKey('message', $record);
        $this->assertArrayHasKey('level', $record);

        $this->assertEquals(Logger::INFO, $record['level']);
        $this->assertRegExp('/Creating SSH tunnel/ui', $record['message']);
    }

    public function testRunWithSSHException()
    {
        $this->expectException('Keboola\DbWriter\Exception\UserException');
        $this->expectExceptionMessageRegExp('/Could not resolve hostname herebedragons/ui');

        $config = $this->config;
        $config['parameters']['db']['ssh'] = [
            'enabled' => true,
            'keys' => [
                '#private' => $this->getPrivateKey(),
                'public' => $this->getEnv('SSH_KEY_PUBLIC')
            ],
            'sshHost' => 'hereBeDragons',
            'localPort' => '33306',
            'remoteHost' => 'mysql',
            'remotePort' => '3306',
        ];

        $this->getApp($config)->run();
    }

    public function testGetTablesInfo()
    {
        $this->runApp($this->getApp($this->config));

        $config = $this->config;
        $config['action'] = 'getTablesInfo';
        $result = $this->getApp($config)->run();
        $resultJson = json_decode($result, true);

        $this->assertContains('encoding', array_keys($resultJson['tables']));
    }

    public function testWrongColumnOrder()
    {
        $this->expectException('Keboola\\DbWriter\\Exception\\UserException');
        $this->expectExceptionMessage('Columns in configuration of table "encoding" does not match with input mapping. Edit and re-save the configuration to fix the problem.');

        // shuffle columns order
        $this->getApp($this->shuffleItems($this->config))->run();
    }

    protected function shuffleItems($config) {
        $col1 =  array_shift($config['parameters']['tables'][0]['items']);
        array_push($config['parameters']['tables'][0]['items'], $col1);

        return $config;
    }

    protected function getApp($config, $logger = null)
    {
        return new Application($config, $logger ?: new Logger($this->appName));
    }

    protected function runApp(Application $app)
    {
        $result = $app->run();

        $encodingIn = $this->dataDir . '/in/tables/encoding.csv';
        $encodingOut = $this->dbTableToCsv($app['writer']->getConnection(), 'encoding', ['col1', 'col2']);

        $this->assertEquals('Writer finished successfully', $result);
        $this->assertFileExists($encodingOut->getPathname());
        $this->assertEquals(file_get_contents($encodingIn), file_get_contents($encodingOut));
    }

    protected function dbTableToCsv(\PDO $conn, $tableName, $header)
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
