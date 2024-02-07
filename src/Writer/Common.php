<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Writer;

use Keboola\Component\UserException;
use Keboola\DbWriterAdapter\Connection\Connection;
use Keboola\DbWriterAdapter\PDO\PdoConnection;
use Keboola\DbWriterAdapter\PDO\PdoWriteAdapter;
use Keboola\DbWriterAdapter\Query\DefaultQueryBuilder;
use Keboola\DbWriterAdapter\WriteAdapter;
use Keboola\DbWriterConfig\Configuration\ValueObject\DatabaseConfig;
use Keboola\DbWriterConfig\Exception\PropertyNotSetException;
use PDO;

class Common extends BaseWriter
{
    /** @var PdoConnection $connection */
    protected Connection $connection;

    /**
     * @throws UserException|PropertyNotSetException
     */
    public function createConnection(DatabaseConfig $databaseConfig): Connection
    {
        $port = $databaseConfig->hasPort() ? $databaseConfig->getPort() : '3306';
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8',
            $databaseConfig->getHost(),
            $port,
            $databaseConfig->getDatabase(),
        );

        return new PdoConnection(
            $this->logger,
            $dsn,
            $databaseConfig->getUser(),
            $databaseConfig->getPassword(),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_LOCAL_INFILE => true,
            ],
            function (PDO $connection): void {
                $connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $connection->exec('SET NAMES utf8;');
            },
        );
    }

    protected function createWriteAdapter(): WriteAdapter
    {
        return new PdoWriteAdapter(
            $this->connection,
            new DefaultQueryBuilder(),
            $this->logger,
        );
    }
}
