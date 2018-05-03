<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Test;

use Keboola\DbWriter\Logger;
use Keboola\DbWriter\WriterFactory;
use Keboola\DbWriter\WriterInterface;
use PHPUnit\Framework\TestCase;

class BaseTest extends TestCase
{
    /** @var string */
    protected $dataDir = __DIR__ . "/../../tests/data";

    /** @var string */
    protected $appName = 'wr-db-common-tests';

    protected function getConfig(): array
    {
        $config = json_decode(file_get_contents($this->dataDir . '/config.json'), true);
        $config['parameters']['data_dir'] = $this->dataDir;
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
            if (false === getenv($env)) {
                throw new \Exception($env . " environment variable must be set.");
            }
        }
        return getenv($env);
    }

    protected function getWriter(array $parameters): WriterInterface
    {
        $writerFactory = new WriterFactory($parameters);

        return $writerFactory->create(new Logger($this->appName));
    }

    public function getPrivateKey(): string
    {
        // docker-compose .env file does not support new lines in variables so we have to modify the key https://github.com/moby/moby/issues/12997
        return str_replace('"', '', str_replace('\n', "\n", $this->getEnv('SSH_KEY_PRIVATE')));
    }
}
