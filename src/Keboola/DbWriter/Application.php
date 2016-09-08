<?php

/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 10/12/15
 * Time: 12:17
 */

namespace Keboola\DbWriter;

use Keboola\DbWriter\Configuration\ConfigDefinition;
use Keboola\DbWriter\Exception\UserException;
use Pimple\Container;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\Exception as ConfigException;
use Symfony\Component\Config\Definition\Processor;

class Application extends Container
{
    private $configDefinition;

    public function __construct($config, Logger $logger)
    {
        parent::__construct();

        $app = $this;
        $this['action'] = isset($config['action'])?$config['action']:'run';
        $this['parameters'] = $config['parameters'];
        $this['logger'] = function() use ($logger) {
            return $logger;
        };
        $this['writer_factory'] = function() use ($app) {
            return new WriterFactory($app['parameters']);
        };
        $this['writer'] = function() use ($app) {
            return $app['writer_factory']->create($app['logger']);
        };
        $this->configDefinition = new ConfigDefinition();
    }

    public function run()
    {
        $this['parameters'] = $this->validateParameters($this['parameters']);

        $actionMethod = $this['action'] . 'Action';
        if (!method_exists($this, $actionMethod)) {
            throw new UserException(sprintf("Action '%s' does not exist.", $this['action']));
        }

        return $this->$actionMethod();
    }

    public function setConfigDefinition(ConfigurationInterface $definition)
    {
        $this->configDefinition = $definition;
    }

    private function validateParameters($parameters)
    {
        try {
            $processor = new Processor();
            $processedParameters = $processor->processConfiguration(
                $this->configDefinition,
                [$parameters]
            );

            if (!empty($processedParameters['db']['#password'])) {
                $processedParameters['db']['password'] = $processedParameters['db']['#password'];
            }

            return $processedParameters;
        } catch (ConfigException $e) {
            throw new UserException($e->getMessage(), 0, $e);
        }
    }

    public function runAction()
    {
        $uploaded = [];
        $tables = array_filter($this['parameters']['tables'], function ($table) {
            return ($table['export']);
        });

        $writer = $this['writer'];
        foreach ($tables as $table) {
            if (!$writer->isTableValid($table)) {
                continue;
            }

            $sourceFilename = $this['parameters']['data_dir'] . "/in/tables/" . $table['tableId'] . ".csv";

            $targetTable = $table['dbName'];
            $table['dbName'] .= $table['incremental']?'_temp_' . uniqid():'';

            try {
                $writer->drop($table['dbName']);
                $writer->create($table);
                $writer->write($sourceFilename, $table);

                if ($table['incremental']) {
                    $writer->upsert($table, $targetTable);
                }
            } catch (\Exception $e) {
                throw new UserException($e->getMessage(), 400, $e);
            }

            $uploaded[] = $table['tableId'];
        }

        return [
            'status' => 'success',
            'uploaded' => $uploaded
        ];
    }

    public function testConnectionAction()
    {
        try {
            $this['writer']->testConnection();
        } catch (\Exception $e) {
            throw new UserException(sprintf("Connection failed: '%s'", $e->getMessage()), 0, $e);
        }

        return [
            'status' => 'success',
        ];
    }

    public function getTablesInfoAction()
    {
        $tables = $this['writer']->showTables($this['parameters']['db']['database']);

        $tablesInfo = [];
        foreach ($tables as $tableName) {
            $tablesInfo[$tableName] = $this['writer']->getTableInfo($tableName);
        }

        return [
            'status' => 'success',
            'tables' => $tablesInfo
        ];
    }
}
