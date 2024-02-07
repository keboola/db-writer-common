<?php

declare(strict_types=1);

namespace Keboola\DbWriter\TestsFunctional;

use Keboola\Component\UserException;
use Keboola\DbWriterAdapter\PDO\PdoConnection;
use Psr\Log\NullLogger;

class PdoTestConnection
{
    public static function getDbConfigArray(bool $ssl = false): array
    {
        $config = [
            'host' => getenv('COMMON_DB_HOST', true) ?: (string) getenv('COMMON_DB_HOST'),
            'port' => (string) getenv('COMMON_DB_PORT'),
            'user' => (string) getenv('COMMON_DB_USER'),
            '#password' => (string) getenv('COMMON_DB_PASSWORD'),
            'database' => (string) getenv('COMMON_DB_DATABASE'),
        ];

        return $config;
    }

    /**
     * @throws UserException
     */
    public static function createConnection(): PdoConnection
    {
        return new PdoConnection(
            new NullLogger(),
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8',
                self::getDbConfigArray()['host'],
                self::getDbConfigArray()['port'],
                self::getDbConfigArray()['database'],
            ),
            self::getDbConfigArray()['user'],
            self::getDbConfigArray()['#password'],
            [],
        );
    }
}
