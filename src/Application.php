<?php

declare(strict_types=1);

namespace Keboola\DbWriter;

use ErrorException;
use Keboola\Csv\CsvFile;
use Keboola\DbWriter\Configuration\ConfigDefinition;
use Keboola\DbWriter\Configuration\ConfigRowDefinition;
use Keboola\DbWriter\Configuration\Validator;
use Keboola\DbWriter\Exception\ApplicationException;
use Keboola\DbWriter\Exception\UserException;
use Pimple\Container;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Application extends Container
{
    public function __construct(array $config, Logger $logger, ?ConfigurationInterface $configDefinition = null)
    {
        parent::__construct();

        $app = $this;

        static::setEnvironment();

        if ($configDefinition == null) {
            if (isset($config['parameters']['tables'])) {
                $configDefinition = new ConfigDefinition();
            } else {
                $configDefinition = new ConfigRowDefinition();
            }
        }
        $validate = Validator::getValidator($configDefinition);

        $this['action'] = isset($config['action'])?$config['action']:'run';
        $this['parameters'] = $validate($config['parameters']);
        $this['logger'] = $logger;
        $this['writer_factory'] = function () use ($app) {
            return new WriterFactory($app['parameters']);
        };
        $this['writer'] = function () use ($app) {
            return $app['writer_factory']->create($app['logger']);
        };
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

        return "Writer finished successfully";
    }

    protected function runWriteTable(array $tableConfig): void
    {
        $csv = $this->getInputCsv($tableConfig['tableId']);
        $tableConfig['items'] = $this->reorderColumns($csv, $tableConfig['items']);

        if (empty($tableConfig['items']) || !$tableConfig['export']) {
            return;
        }

        try {
            if ($tableConfig['incremental']) {
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

    public function writeIncremental(CsvFile $csv, array $tableConfig): void
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

    public function writeFull(CsvFile $csv, array $tableConfig): void
    {
        /** @var WriterInterface $writer */
        $writer = $this['writer'];

        $writer->drop($tableConfig['dbName']);
        $writer->create($tableConfig);
        $writer->write($csv, $tableConfig);
    }

    protected function reorderColumns(CsvFile $csv, array $items): array
    {
        $csv->next();
        $csvHeader = $csv->current();
        $csv->rewind();

        $reordered = [];
        foreach ($csvHeader as $csvCol) {
            foreach ($items as $item) {
                if ($csvCol == $item['name']) {
                    $reordered[] = $item;
                }
            }
        }

        return $reordered;
    }

    protected function getInputCsv(string $tableId): CsvFile
    {
        return new CsvFile($this['parameters']['data_dir'] . "/in/tables/" . $tableId . ".csv");
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
}
