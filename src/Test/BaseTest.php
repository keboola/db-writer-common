<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Test;

use Exception;
use Keboola\DbWriter\WriterFactory;
use Keboola\DbWriter\WriterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class BaseTest extends TestCase
{
    private string $rootDir = __DIR__ . '/../../';

    protected string $dataDir = __DIR__ . '/../../tests/data/deprecated';

    protected string $tmpDataDir = '/tmp/wr-db/data';

    protected string $appName = 'wr-db-common-tests';

    protected function setUp(): void
    {
        parent::setUp();
        $this->closeSshTunnels();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->closeSshTunnels();
    }

    protected function closeSshTunnels(): void
    {
        # Close SSH tunnel if created
        $process = new Process(['sh', '-c', 'pgrep ssh | xargs -r kill']);
        $process->mustRun();
    }

    protected function getConfig(?string $dataDir = null): array
    {
        $dataDir = $dataDir ?: $this->dataDir;
        $config = json_decode(file_get_contents($dataDir . '/config.json'), true);
        $config['parameters']['data_dir'] = $dataDir;
        $config['parameters']['db']['user'] = $this->getEnv('DB_USER', true);
        $config['parameters']['db']['#password'] = $this->getEnv('DB_PASSWORD', true);
        $config['parameters']['db']['host'] = $this->getEnv('DB_HOST');
        $config['parameters']['db']['port'] = $this->getEnv('DB_PORT');
        $config['parameters']['db']['database'] = $this->getEnv('DB_DATABASE');

        return $config;
    }

    protected function getEnv(string $name, bool $required = false): string
    {
        $env = strtoupper($name);
        if ($required) {
            if (getenv($env) === false) {
                throw new Exception($env . ' environment variable must be set.');
            }
        }
        return getenv($env);
    }

    protected function getWriter(array $parameters): WriterInterface
    {
        $writerFactory = new WriterFactory($parameters);

        return $writerFactory->create(new TestLogger());
    }

    public function getPrivateKey(): string
    {
        return file_get_contents('/root/.ssh/id_rsa');
    }

    public function getPublicKey(): string
    {
        return file_get_contents('/root/.ssh/id_rsa.pub');
    }

    public function initFixtures(array $config, ?string $sourceDataDir = null): void
    {
        $dataDir = $sourceDataDir ?: $this->dataDir;

        $fs = new Filesystem();
        if ($fs->exists($this->tmpDataDir)) {
            $fs->remove($this->tmpDataDir);
        }
        $fs->mkdir($this->tmpDataDir);
        $fs->dumpFile($this->tmpDataDir . '/config.json', json_encode($config));
        $fs->mirror($dataDir . '/in/tables', $this->tmpDataDir . '/in/tables');
    }

    protected function runProcess(): Process
    {
        $process = new Process(sprintf('php %srun.php --data=%s 2>&1', $this->rootDir, $this->tmpDataDir));
        $process->setTimeout(300);
        $process->mustRun();

        return $process;
    }
}
