<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 25/05/15
 * Time: 15:19
 */

namespace Keboola\DbWriter;

use Keboola\DbWriter\Exception\UserException;

class WriterFactory
{
    private $parameters;

    public function __construct($parameters)
    {
        $this->parameters = $parameters;
    }

    /**
     * @param $logger
     * @return WriterInterface
     * @throws UserException
     */
    public function create($logger)
    {
        $writerClass = __NAMESPACE__ . '\\Writer\\' . $this->parameters['writer_class'];
        if (!class_exists($writerClass)) {
            throw new UserException(sprintf("Writer class '%s' doesn't exist", $writerClass));
        }

        return new $writerClass($this->parameters['db'], $logger);
    }

}
