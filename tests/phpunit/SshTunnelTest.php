<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Tests;

use Generator;
use Keboola\DbWriter\TestsTraits\CloseSshTunnelsTrait;
use Keboola\DbWriter\Writer\SshTunnel;
use Keboola\DbWriterConfig\Configuration\ValueObject\DatabaseConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;
use Symfony\Component\Process\Process;
use Throwable;

class SshTunnelTest extends TestCase
{
    use CloseSshTunnelsTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->closeSshTunnels();
    }

    public function testOpenTunnel(): void
    {
        $configArray = self::getDatabaseConfig();
        $newConfig = $this->openTunnel($configArray);

        self::assertEquals('127.0.0.1', $newConfig->getHost());
        self::assertEquals('33006', $newConfig->getPort());
        self::assertTrue(self::checkIfTunnelIsOpen());
    }

    public function testDisabledSSHConfig(): void
    {
        $configArray = self::getDatabaseConfig();
        $configArray['ssh']['enabled'] = false;

        $newConfig = $this->openTunnel($configArray);

        self::assertEquals((string) getenv('COMMON_DB_HOST'), $newConfig->getHost());
        self::assertEquals((string) getenv('COMMON_DB_PORT'), $newConfig->getPort());
        self::assertFalse(self::checkIfTunnelIsOpen());
    }

    /**
     * @param class-string<object> $expectException
     * @dataProvider invalidConfigDataProvider
     */
    public function testInvalidConfig(array $config, string $expectException, string $expectExceptionMessage): void
    {
        try {
            $this->openTunnel($config);
            self::fail('Exception should be thrown');
        } catch (Throwable $e) {
            self::assertInstanceOf($expectException, $e);
            self::assertEquals($expectExceptionMessage, $e->getMessage());
        }
    }

    private static function getDatabaseConfig(): array
    {
        return [
            'host' => (string) getenv('COMMON_DB_HOST'),
            'port' => (string) getenv('COMMON_DB_PORT'),
            'database' => (string) getenv('COMMON_DB_DATABASE'),
            'user' => (string) getenv('COMMON_DB_USER'),
            '#password' => (string) getenv('COMMON_DB_PASSWORD'),
            'schema' => (string) getenv('COMMON_DB_SCHEMA'),
            'ssh' => [
                'enabled' => true,
                'keys' => [
                    '#private' => (string) file_get_contents('/root/.ssh/id_rsa'),
                    'public' => (string) file_get_contents('/root/.ssh/id_rsa.pub'),
                ],
                'sshHost' => 'sshproxy',
            ],
        ];
    }

    private static function checkIfTunnelIsOpen(): bool
    {
        # Find open ssh tunnels
        $process = new Process(['sh', '-c', 'ps aux | grep -i "ssh -p 22" | grep -v grep | wc -l']);
        $process->mustRun();

        $count = (int) $process->getOutput();
        return $count > 0;
    }

    private function openTunnel(array $configArray): DatabaseConfig
    {
        $logger = new TestLogger();
        $sshTunnel = new SshTunnel($logger);

        $databaseConfig = DatabaseConfig::fromArray($configArray);

        return $sshTunnel->createSshTunnel($databaseConfig);
    }

    public function invalidConfigDataProvider(): Generator
    {
        $config = self::getDatabaseConfig();
        $config['ssh']['keys']['#private'] = '';
        yield 'ssh-host-missing' => [
            $config,
            'Keboola\DbWriter\Exception\UserException',
            'Key must not be emptyRetries count: 5',
        ];
    }
}
