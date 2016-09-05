<?php
/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 02/09/16
 * Time: 15:13
 */

namespace Keboola\DbWriter;

use Keboola\DbWriter\Test\BaseTest;

class ApplicationTest extends BaseTest
{
    private $config;

    public function setUp()
    {
        parent::setUp();
        $this->config = $this->getConfig('mssql');
    }

    public function testRun()
    {
        $this->runApp(new Application($this->config));
    }

    public function testRunWithSSH()
    {
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
        $this->runApp(new Application($config));
    }

    protected function runApp(Application $app)
    {
        $result = $app->run();
        $expectedCsvFile = ROOT_PATH . '/tests/data/escaping.csv';
        $outputCsvFile = $this->dataDir . '/out/tables/' . $result['imported'][0] . '.csv';
        $outputManifestFile = $this->dataDir . '/out/tables/' . $result['imported'][0] . '.csv.manifest';

        $this->assertEquals('success', $result['status']);
        $this->assertFileExists($outputCsvFile);
        $this->assertFileExists($outputManifestFile);
        $this->assertEquals(file_get_contents($expectedCsvFile), file_get_contents($outputCsvFile));
    }
}
