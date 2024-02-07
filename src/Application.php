<?php

declare(strict_types=1);

namespace Keboola\DbWriter;

use Keboola\Component\BaseComponent;
use Keboola\DbWriter\Exception\ApplicationException;
use Keboola\DbWriter\Exception\InvalidDatabaseHostException;
use Keboola\DbWriter\Exception\UserException;
use Keboola\DbWriterConfig\Configuration\ActionConfigDefinition;
use Keboola\DbWriterConfig\Configuration\ConfigDefinition;
use Keboola\DbWriterConfig\Configuration\ConfigRowDefinition;
use Keboola\DbWriterConfig\Configuration\ValueObject\DatabaseConfig;
use Keboola\DbWriterConfig\Configuration\ValueObject\ExportConfig;
use Psr\Log\LoggerInterface;
use Throwable;

class Application extends BaseComponent
{

    protected string $writerName = 'Common';

    /**
     * @throws InvalidDatabaseHostException
     */
    public function __construct(LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->checkDatabaseHost();
    }

    protected function getRawConfig(): array
    {
        $rawConfig = parent::getRawConfig();
        $rawConfig['parameters']['writer_class'] = $this->writerName;
        $rawConfig['parameters']['data_dir'] = $this->getDataDir();
        return $rawConfig;
    }

    /**
     * @throws UserException|ApplicationException
     */
    protected function run(): void
    {
        $parameters = $this->getConfig()->getParameters();
        $writerFactory = new WriterFactory($this->getConfig());
        $writer = $writerFactory->create($this->getLogger(), $this->createDatabaseConfig($parameters['db']));

        if (!$this->isRowConfiguration($parameters)) {
            $filteredTables = array_filter($parameters['tables'], fn($table) => $table['export']);
            unset($parameters['tables']);
            foreach ($filteredTables as $filteredTable) {
                $filteredTable = $this->validateTableItems($filteredTable);
                $filteredTable = array_merge($parameters, $filteredTable);
                $writer->write($this->createExportConfig($filteredTable));
            }
        } else {
            $parameters = $this->validateTableItems($parameters);
            $writer->write($this->createExportConfig($parameters));
        }
    }

    /**
     * @throws UserException
     */
    public function testConnectionAction(): array
    {
        $config = $this->getConfig();
        $writerFactory = new WriterFactory($config);
        $writer = $writerFactory->create(
            $this->getLogger(),
            $this->createDatabaseConfig($config->getParameters()['db']),
        );
        try {
            $writer->testConnection();
        } catch (Throwable $e) {
            throw new UserException(sprintf("Connection failed: '%s'", $e->getMessage()), 0, $e);
        }

        return [
            'status' => 'success',
        ];
    }

    /**
     * @throws UserException
     */
    public function getTablesInfoAction(): array
    {
        $config = $this->getConfig();
        $writerFactory = new WriterFactory($config);
        $writer = $writerFactory->create(
            $this->getLogger(),
            $this->createDatabaseConfig($config->getParameters()['db']),
        );

        $tables = $writer->showTables();

        $tablesInfo = [];
        foreach ($tables as $tableName) {
            $tablesInfo[$tableName] = $writer->getTableInfo($tableName);
        }

        return [
            'status' => 'success',
            'tables' => $tablesInfo,
        ];
    }

    protected function createExportConfig(array $table): ExportConfig
    {
        return ExportConfig::fromArray(
            $table,
            $this->getConfig()->getInputTables(),
            $this->createDatabaseConfig($table['db']),
        );
    }

    protected function createDatabaseConfig(array $dbParams): DatabaseConfig
    {
        return DatabaseConfig::fromArray($dbParams);
    }

    protected function getSyncActions(): array
    {
        return [
            'testConnection' => 'testConnectionAction',
            'getTablesInfo' => 'getTablesInfoAction',
        ];
    }

    protected function getConfigDefinitionClass(): string
    {
        if ($this->isRowConfiguration($this->getRawConfig()['parameters'])) {
            $action = $this->getRawConfig()['action'] ?? 'run';
            if ($action === 'run') {
                return ConfigRowDefinition::class;
            } else {
                return ActionConfigDefinition::class;
            }
        }

        return ConfigDefinition::class;
    }

    protected function getValidator(): Validator
    {
        return new Validator($this->getLogger());
    }

    protected function isRowConfiguration(array $parameters): bool
    {
        return !isset($parameters['tables']);
    }

    /**
     * @throws UserException|ApplicationException
     */
    protected function validateTableItems(array $table): array
    {
        $validator = $this->getValidator();
        $table['items'] = $validator->validateTableItems($this->getInputTablePath($table['tableId']), $table['items']);
        return $table;
    }

    private function getInputTablePath(string $tableId): string
    {
        $inputMapping = $this->getConfig()->getInputTables();
        if (!$inputMapping) {
            throw new ApplicationException('Missing storage input mapping.');
        }

        $filteredStorageInputMapping = array_filter($inputMapping, function ($v) use ($tableId) {
            return $v['source'] === $tableId;
        });

        if (count($filteredStorageInputMapping) === 0) {
            throw new UserException(sprintf(
                'Table "%s" in storage input mapping cannot be found.',
                $tableId,
            ));
        }

        $tableFromInputMapping = current($filteredStorageInputMapping);

        return sprintf(
            '%s/in/tables/%s',
            $this->getDataDir(),
            $tableFromInputMapping['destination'],
        );
    }

    /**
     * @throws InvalidDatabaseHostException
     */
    private function checkDatabaseHost(): void
    {
        $checker = $this->getValidator();
        $checker->validateDatabaseHost($this->getConfig());
    }
}
