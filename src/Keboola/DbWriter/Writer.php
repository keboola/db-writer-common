<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 05/09/14
 * Time: 12:53
 */

namespace Keboola\DbWriter;

use Keboola\DbWriter\Exception\ApplicationException;
use Keboola\DbWriter\Exception\UserException;

abstract class Writer implements WriterInterface
{
    protected $db;

    protected $async = false;

    /** @var Logger */
    protected $logger;

    public function __construct($dbParams, Logger $logger)
    {
        $this->logger = $logger;

        try {
            $this->db = $this->createConnection($dbParams);
        } catch (\Exception $e) {
            if (strstr(strtolower($e->getMessage()), 'could not find driver')) {
                throw new ApplicationException("Missing driver: " . $e->getMessage());
            }
            throw new UserException("Error connecting to DB: " . $e->getMessage(), 0, $e);
        }
    }

    public function getConnection()
    {
        return $this->db;
    }

    /**
     * @return boolean
     */
    public function isAsync()
    {
        return $this->async;
    }

    public function writeAsync($tableId, $outputTableName)
    {
        throw new ApplicationException("Not implemented.");
    }

}
