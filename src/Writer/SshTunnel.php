<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Writer;

use Keboola\DbWriter\Exception\SshException as DbWriterSshException;
use Keboola\DbWriter\Exception\UserException;
use Keboola\DbWriterConfig\Configuration\ValueObject\DatabaseConfig;
use Keboola\DbWriterConfig\Exception\PropertyNotSetException;
use Keboola\SSHTunnel\SSH;
use Keboola\SSHTunnel\SSHException;
use Psr\Log\LoggerInterface;
use Retry\BackOff\ExponentialBackOffPolicy;
use Retry\Policy\SimpleRetryPolicy;
use Retry\RetryProxy;
use Throwable;

class SshTunnel
{
    public const DEFAULT_SSH_PORT = '22';
    public const DEFAULT_LOCAL_PORT = '33006';
    public const DEFAULT_MAX_TRIES = 5;

    public function __construct(readonly private LoggerInterface $logger)
    {
    }

    /**
     * @throws PropertyNotSetException|UserException|DbWriterSshException
     */
    public function createSshTunnel(DatabaseConfig $config): DatabaseConfig
    {
        // check main param
        if (!$config->hasSshConfig()) {
            return $config;
        }

        $sshConfig = $config->getSshConfig()->toArray();

        $sshConfig['remoteHost'] = $config->getHost();
        $sshConfig['remotePort'] = $config->getPort();
        $sshConfig['privateKey'] = $sshConfig['keys']['#private'];

        if (empty($sshConfig['user'])) {
            $sshConfig['user'] = $config->getUser();
        }
        if (empty($sshConfig['localPort'])) {
            $sshConfig['localPort'] = self::DEFAULT_LOCAL_PORT;
        }
        if (empty($sshConfig['sshPort'])) {
            $sshConfig['sshPort'] = self::DEFAULT_SSH_PORT;
        }

        $tunnelParams = array_intersect_key(
            $sshConfig,
            array_flip(
                [
                    'user', 'sshHost', 'sshPort', 'localPort', 'remoteHost', 'remotePort', 'privateKey', 'compression',
                ],
            ),
        );
        $this->logger->info(sprintf(
            "Creating SSH tunnel to '%s' on local port '%s'",
            $tunnelParams['sshHost'],
            $tunnelParams['localPort'],
        ));

        $simplyRetryPolicy = new SimpleRetryPolicy(
            self::DEFAULT_MAX_TRIES,
            [SSHException::class, Throwable::class],
        );

        $exponentialBackOffPolicy = new ExponentialBackOffPolicy();
        $proxy = new RetryProxy(
            $simplyRetryPolicy,
            $exponentialBackOffPolicy,
            $this->logger,
        );

        try {
            /** @throws SSHException */
            $proxy->call(function () use ($tunnelParams): void {
                $ssh = new SSH();
                $ssh->openTunnel($tunnelParams);
            });
        } catch (SSHException $e) {
            throw new UserException($e->getMessage() . 'Retries count: ' . $proxy->getTryCount(), 0, $e);
        }

        $dbConfig = $config->toArray();
        $dbConfig['ssh'] = $sshConfig;
        $dbConfig['host'] = '127.0.0.1';
        $dbConfig['port'] = $sshConfig['localPort'];

        return get_class($config)::fromArray($dbConfig);
    }
}
