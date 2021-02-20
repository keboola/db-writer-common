<?php

declare(strict_types=1);

namespace Keboola\DbWriter;

use SplFileInfo;
use ErrorException;
use Keboola\Component\Logger\AsyncActionLogging;
use Keboola\Component\Logger\SyncActionLogging;
use Keboola\Csv\CsvReader;
use Keboola\DbWriter\Configuration\ConfigDefinition;
use Keboola\DbWriter\Configuration\ConfigRowDefinition;
use Keboola\DbWriter\Configuration\Validator;
use Keboola\DbWriter\Exception\ApplicationException;
use Keboola\DbWriter\Exception\UserException;
use Pimple\Container;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Application extends Container
{
    public function __construct(
        array $config,
        LoggerInterface $logger,
        ?ConfigurationInterface $configDefinition = null
    ) {
        parent::__construct();

        if (isset($config['image_parameters']) && isset($config['image_parameters']['approvedHostnames'])) {
            $this->validateHostname(
                $config['image_parameters']['approvedHostnames'],
                $config['parameters']['db']
            );
        }

        $app = $this;

        static::setEnvironment();

        if ($configDefinition === null) {
            if (isset($config['parameters']['tables'])) {
                $configDefinition = new ConfigDefinition();
            } else {
                $configDefinition = new ConfigRowDefinition();
            }
        }
        $validate = Validator::getValidator($configDefinition);

        $this['inputMapping'] = $config['storage']['input'] ?? null;
        $this['action'] = isset($config['action'])?$config['action']:'run';
        $this['parameters'] = $validate($config['parameters']);
        $this['logger'] = $logger;
        $this['writer'] = function () use ($app) {
            return $this->getWriterFactory($app['parameters'])->create($app['logger']);
        };

        // Setup logger, copied from php-component/src/BaseComponent.php
        // Will be removed in next refactoring steps,
        // ... when Application will be replace by standard BaseComponent
        if ($this['action'] !== 'run') { // $this->isSyncAction()
            if ($this['logger'] instanceof SyncActionLogging) {
                $this['logger']->setupSyncActionLogging();
            }
        } else {
            if ($this['logger']instanceof AsyncActionLogging) {
                $this['logger']->setupAsyncActionLogging();
            }
        }
    }

    public static function setEnvironment(): void
    {
        error_reporting(E_ALL);
        set_error_handler(function ($errno, $errstr, $errfile, $errline, array $errcontext): bool {
            if (!(error_reporting() & $errno)) {
                // respect error_reporting() level
                // libraries used in custom components may emit notices that cannot be fixed
                return false;
            }
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
    }

    public function run(): string
    {
        $actionMethod = $this['action'] . 'Action';
        if (!method_exists($this, $actionMethod)) {
            throw new UserException(sprintf("Action '%s' does not exist.", $this['action']));
        }

        return $this->$actionMethod();
    }

    public function runAction(): string
    {
        if (isset($this['parameters']['tables'])) {
            $tables = array_filter($this['parameters']['tables'], function ($table) {
                return ($table['export']);
            });
            foreach ($tables as $tableConfig) {
                $this->runWriteTable($tableConfig);
            }
        } else {
            $this->runWriteTable($this['parameters']);
        }

        return 'Writer finished successfully';
    }

    protected function runWriteTable(array $tableConfig): void
    {
        $csv = $this->getInputCsv($tableConfig['tableId']);
        $tableConfig['items'] = $this->reorderColumns($csv, $tableConfig['items']);

        $export = isset($tableConfig['export']) ? $tableConfig['export'] : true;
        if (empty($tableConfig['items']) || $export === false) {
            return;
        }

        try {
            if (isset($tableConfig['incremental']) && $tableConfig['incremental']) {
                $this->writeIncremental($csv, $tableConfig);
            } else {
                $this->writeFull($csv, $tableConfig);
            }
        } catch (\PDOException $e) {
            throw new UserException($e->getMessage(), 0, $e);
        } catch (UserException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ApplicationException($e->getMessage(), 2, $e);
        }
    }

    public function writeIncremental(SplFileInfo $csv, array $tableConfig): void
    {
        /** @var WriterInterface $writer */
        $writer = $this['writer'];

        // write to staging table
        $stageTable = $tableConfig;
        $stageTable['dbName'] = $writer->generateTmpName($tableConfig['dbName']);
        $stageTable['temporary'] = true;

        $writer->drop($stageTable['dbName']);
        $writer->create($stageTable);
        $writer->write($csv, $stageTable);

        // create destination table if not exists
        $dstTableExists = $writer->tableExists($tableConfig['dbName']);
        if (!$dstTableExists) {
            $writer->create($tableConfig);
        }
        $writer->validateTable($tableConfig);

        // upsert from staging to destination table
        $writer->upsert($stageTable, $tableConfig['dbName']);
    }

    public function writeFull(SplFileInfo $csv, array $tableConfig): void
    {
        /** @var WriterInterface $writer */
        $writer = $this['writer'];

        $writer->drop($tableConfig['dbName']);
        $writer->create($tableConfig);
        $writer->write($csv, $tableConfig);
    }

    protected function reorderColumns(SplFileInfo $csv, array $items): array
    {
        $csvReader = new CsvReader($csv->getPathname());
        $csvHeader = $csvReader->getHeader();
        $reordered = [];
        foreach ($csvHeader as $csvCol) {
            foreach ($items as $item) {
                if ($csvCol === $item['name']) {
                    $reordered[] = $item;
                }
            }
        }

        return $reordered;
    }

    protected function getInputCsv(string $tableId): SplFileInfo
    {
        $inputMapping = $this['inputMapping'];
        if (!$inputMapping) {
            throw new ApplicationException('Missing storage input mapping.');
        }

        $filteredStorageInputMapping = array_filter($inputMapping['tables'], function ($v) use ($tableId) {
            return $v['source'] === $tableId;
        });

        if (count($filteredStorageInputMapping) === 0) {
            throw new UserException(
                sprintf('Table "%s" in storage input mapping cannot be found.', $tableId)
            );
        }

        $filteredStorageInputMapping = array_values($filteredStorageInputMapping);

        return new SplFileInfo(
            sprintf(
                '%s/in/tables/%s',
                $this['parameters']['data_dir'],
                $filteredStorageInputMapping[0]['destination']
            )
        );
    }

    public function testConnectionAction(): string
    {
        try {
            $this['writer']->testConnection();
        } catch (\Throwable $e) {
            throw new UserException(sprintf("Connection failed: '%s'", $e->getMessage()), 0, $e);
        }

        return json_encode([
            'status' => 'success',
        ]);
    }

    public function getTablesInfoAction(): string
    {
        $tables = $this['writer']->showTables($this['parameters']['db']['database']);

        $tablesInfo = [];
        foreach ($tables as $tableName) {
            $tablesInfo[$tableName] = $this['writer']->getTableInfo($tableName);
        }

        return json_encode([
            'status' => 'success',
            'tables' => $tablesInfo,
        ]);
    }

    protected function getWriterFactory(array $parameters): WriterFactory
    {
        return new WriterFactory($parameters);
    }

    protected function validateHostname(array $approvedHostnames, array $db): void
    {
        $validHostname = array_filter($approvedHostnames, function ($v) use ($db) {
            return $v['host'] === $db['host'] && $v['port'] === $db['port'];
        });

        if (count($validHostname) === 0) {
            throw new UserException(
                sprintf(
                    'Hostname "%s" with port "%s" is not approved.',
                    $db['host'],
                    $db['port']
                )
            );
        }
    }
}
