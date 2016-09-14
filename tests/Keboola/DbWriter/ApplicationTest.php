<?php
/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 02/09/16
 * Time: 15:13
 */

namespace Keboola\DbWriter;

use Keboola\Csv\CsvFile;
use Keboola\DbWriter\Test\BaseTest;
use Monolog\Handler\TestHandler;

class ApplicationTest extends BaseTest
{
    private $config;

    public function setUp()
    {
        parent::setUp();
        $this->config = $this->getConfig('common');
    }

    public function testRun()
    {
        $this->runApp(new Application($this->config, new Logger(APP_NAME)));
    }

    public function testRunWithSSH()
    {
        $testHandler = new TestHandler();

        $logger = new Logger(APP_NAME);
        $logger->setHandlers([$testHandler]);


        $config = $this->config;
        $config['parameters']['db']['ssh'] = [
            'enabled' => true,
            'keys' => [
                '#private' => $this->getEnv('common', 'DB_SSH_KEY_PRIVATE'),
                'public' => $this->getEnv('common', 'DB_SSH_KEY_PUBLIC')
            ],
            'sshHost' => 'sshproxy',
            'localPort' => '33306',
            'remoteHost' => 'mysql',
            'remotePort' => '3306',
        ];
        $this->runApp(new Application($config, $logger));

        $records = $testHandler->getRecords();
        $record = reset($records);

        $this->assertCount(1, $testHandler->getRecords());

        $this->assertArrayHasKey('message', $record);
        $this->assertArrayHasKey('level', $record);

        $this->assertEquals(Logger::INFO, $record['level']);
        $this->assertRegExp('/Creating SSH tunnel/ui', $record['message']);
    }

    public function testRunReorderColumns()
    {
        $simpleTableCfg = $this->config['parameters']['tables'][1];
        $firstCol = $simpleTableCfg['items'][0];
        $secondCol = $simpleTableCfg['items'][1];
        $simpleTableCfg['items'][0] = $secondCol;
        $simpleTableCfg['items'][1] = $firstCol;
        $this->config['parameters']['tables'][1] = $simpleTableCfg;

        $this->runApp(new Application($this->config, new Logger(APP_NAME)));
    }

    public function testGetTablesInfo()
    {
        $config = $this->config;
        $config['action'] = 'getTablesInfo';
        $result = (new Application($config, new Logger(APP_NAME)))->run();

        $this->assertContains('encoding', array_keys($result['tables']));
    }

    protected function runApp(Application $app)
    {
        $result = $app->run();

        $encodingIn = $this->dataDir . '/in/tables/encoding.csv';
        $encodingOut = $this->dbTableToCsv($app['writer']->getConnection(), 'encoding', ['col1', 'col2']);

        $this->assertEquals('success', $result['status']);
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
