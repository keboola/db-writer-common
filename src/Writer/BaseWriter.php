<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Writer;

use Keboola\DbWriter\Exception\SshException;
use Keboola\DbWriter\Exception\UserException;
use Keboola\DbWriterAdapter\Connection\Connection;
use Keboola\DbWriterAdapter\WriteAdapter;
use Keboola\DbWriterConfig\Configuration\ValueObject\DatabaseConfig;
use Keboola\DbWriterConfig\Configuration\ValueObject\ExportConfig;
use Keboola\DbWriterConfig\Exception\PropertyNotSetException;
use Psr\Log\LoggerInterface;

abstract class BaseWriter
{
    protected Connection $connection;

    protected WriteAdapter $adapter;

    /**
     * @throws UserException|SshException|PropertyNotSetException
     */
    public function __construct(
        DatabaseConfig $databaseConfig,
        readonly protected LoggerInterface $logger,
    ) {
        $databaseConfig = $this->createSshTunnel($databaseConfig);
        $this->connection = $this->createConnection($databaseConfig);
        $this->adapter = $this->createWriteAdapter();
    }

    abstract protected function createConnection(DatabaseConfig $databaseConfig): Connection;

    abstract protected function createWriteAdapter(): WriteAdapter;

    public function write(ExportConfig $exportConfig): void
    {
        if ($exportConfig->isIncremental()) {
            $this->writeIncremental($exportConfig);
        } else {
            $this->writeFull($exportConfig);
        }
    }

    public function testConnection(): void
    {
        $this->connection->testConnection();
    }

    public function showTables(): array
    {
        return $this->adapter->showTables();
    }

    public function getTableInfo(string $tableName): array
    {
        return $this->adapter->getTableInfo($tableName);
    }

    protected function writeIncremental(ExportConfig $exportConfig): void
    {
        // write to staging table
        $stageTableName = $this->adapter->generateTmpName($exportConfig->getDbName());

        $this->adapter->drop($stageTableName);
        $this->adapter->create(
            $stageTableName,
            true,
            $exportConfig->getItems(),
            $exportConfig->hasPrimaryKey() ? $exportConfig->getPrimaryKey() : null,
        );
        $this->adapter->writeData($stageTableName, $exportConfig);

        // create destination table if not exists
        if (!$this->adapter->tableExists($exportConfig->getDbName())) {
            $this->adapter->create(
                $exportConfig->getDbName(),
                false,
                $exportConfig->getItems(),
                $exportConfig->hasPrimaryKey() ? $exportConfig->getPrimaryKey() : null,
            );
        }
        $this->adapter->validateTable($exportConfig->getDbName(), $exportConfig->getItems());

        // upsert from staging to destination table
        $this->adapter->upsert($exportConfig, $stageTableName);
    }

    protected function writeFull(ExportConfig $exportConfig): void
    {
        $this->adapter->drop($exportConfig->getDbName());
        $this->adapter->create(
            $exportConfig->getDbName(),
            false,
            $exportConfig->getItems(),
            $exportConfig->hasPrimaryKey() ? $exportConfig->getPrimaryKey() : null,
        );
        $this->adapter->writeData($exportConfig->getDbName(), $exportConfig);
    }

    /**
     * @throws UserException|SshException
     * @throws PropertyNotSetException
     */
    protected function createSshTunnel(DatabaseConfig $databaseConfig): DatabaseConfig
    {
        $sshTunnel = new SshTunnel($this->logger);
        return $sshTunnel->createSshTunnel($databaseConfig);
    }
}
