<?php

declare(strict_types=1);

namespace Keboola\DbWriter;

use Keboola\DbWriter\Exception\UserException;
use Monolog\Logger;

class WriterFactory
{
    /** @var array */
    private $parameters;

    public function __construct(array $parameters)
    {
        $this->parameters = $parameters;
    }

    public function create(Logger $logger): WriterInterface
    {
        $writerClass = __NAMESPACE__ . '\\Writer\\' . $this->parameters['writer_class'];
        if (!class_exists($writerClass)) {
            throw new UserException(sprintf("Writer class '%s' doesn't exist", $writerClass));
        }

        return new $writerClass($this->parameters['db'], $logger);
    }
}
