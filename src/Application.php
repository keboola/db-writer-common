<?php

namespace Keboola\DbWriter;

use ErrorException;
use Keboola\Csv\CsvFile;
use Keboola\DbWriter\Configuration\ConfigDefinition;
use Keboola\DbWriter\Configuration\Validator;
use Keboola\DbWriter\Exception\ApplicationException;
use Keboola\DbWriter\Exception\UserException;
use Pimple\Container;

class Application extends Container
{
    public function __construct($config, Logger $logger, $configDefinition = null)
    {
        parent::__construct();

        $app = $this;

        static::setEnvironment();

        if ($configDefinition == null) {
            $configDefinition = new ConfigDefinition();
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

    public static function setEnvironment()
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

    /**
     * @return string
     * @throws UserException
     */
    public function run()
    {
        $actionMethod = $this['action'] . 'Action';
        if (!method_exists($this, $actionMethod)) {
            throw new UserException(sprintf("Action '%s' does not exist.", $this['action']));
        }

        return $this->$actionMethod();
    }

    public function runAction()
    {
        $tables = array_filter($this['parameters']['tables'], function ($table) {
            return ($table['export']);
        });

        foreach ($tables as $tableConfig) {
            $csv = $this->getInputCsv($tableConfig['tableId']);
            $this->checkColumns($tableConfig);

            if (empty($tableConfig['items'])) {
                continue;
            }

            try {
                if ($tableConfig['incremental']) {
                    $this->writeIncremental($csv, $tableConfig);
                    continue;
                }

                $this->writeFull($csv, $tableConfig);
            } catch (\PDOException $e) {
                $this['logger']->error($e->getMessage());
                throw new UserException($e->getMessage(), 0, $e);
            } catch (UserException $e) {
                $this['logger']->error($e->getMessage());
                throw $e;
            } catch (\Exception $e) {
                throw new ApplicationException($e->getMessage(), 2, $e);
            }
        }

        return "Writer finished successfully";
    }

    public function writeIncremental($csv, $tableConfig)
    {
        /** @var WriterInterface $writer */
        $writer = $this['writer'];

        // write to staging table
        $stageTable = $tableConfig;
        $stageTable['dbName'] = $writer->generateTmpName($tableConfig['dbName']);

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

    public function writeFull($csv, $tableConfig)
    {
        /** @var WriterInterface $writer */
        $writer = $this['writer'];

        $writer->drop($tableConfig['dbName']);
        $writer->create($tableConfig);
        $writer->write($csv, $tableConfig);
    }

    protected function getInputMapping($tableId)
    {
        foreach ($this['inputMapping'] as $inputTable) {
            if ($tableId == $inputTable['source']) {
                return $inputTable;
            }
        }
        throw new UserException(sprintf(
            'Table "%s" is missing from input mapping. Reloading the page and re-saving configuration may fix the problem.',
            $tableId
        ));
    }

    /**
     * Check if input mapping is aligned with table config
     *
     * @param $tableConfig
     * @throws UserException
     */
    protected function checkColumns($tableConfig)
    {
        $inputMapping = $this->getInputMapping($tableConfig['tableId']);
        $mappingColumns = $inputMapping['columns'];
        $tableColumns = array_map(function ($item) {
            return $item['name'];
        }, $tableConfig['items']);
        if ($mappingColumns !== $tableColumns) {
            throw new UserException(sprintf(
                'Columns in configuration of table "%s" does not match with input mapping. Edit and re-save the configuration to fix the problem.',
                $inputMapping['source']
            ));
        }
    }

    protected function getInputCsv($tableId)
    {
        return new CsvFile($this['parameters']['data_dir'] . "/in/tables/" . $tableId . ".csv");
    }

    public function testConnectionAction()
    {
        try {
            $this['writer']->testConnection();
        } catch (\Exception $e) {
            throw new UserException(sprintf("Connection failed: '%s'", $e->getMessage()), 0, $e);
        }

        return json_encode([
            'status' => 'success',
        ]);
    }

    public function getTablesInfoAction()
    {
        $tables = $this['writer']->showTables($this['parameters']['db']['database']);

        $tablesInfo = [];
        foreach ($tables as $tableName) {
            $tablesInfo[$tableName] = $this['writer']->getTableInfo($tableName);
        }

        return json_encode([
            'status' => 'success',
            'tables' => $tablesInfo
        ]);
    }
}
