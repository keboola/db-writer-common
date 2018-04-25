<?php

namespace Keboola\DbWriter;

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
            $tableConfig['items'] = $this->reorderColumns($csv, $tableConfig['items']);

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

    protected function reorderColumns(CsvFile $csv, $items)
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
