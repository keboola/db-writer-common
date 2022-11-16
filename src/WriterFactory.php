<?php

declare(strict_types=1);

namespace Keboola\DbWriter;

use Keboola\DbWriter\Exception\UserException;
use Psr\Log\LoggerInterface;

class WriterFactory
{
    /** @var array */
    private array $parameters;

    public function __construct(array $parameters)
    {
        $this->parameters = $parameters;
    }

    public function create(LoggerInterface $logger): WriterInterface
    {
        $writerClass = __NAMESPACE__ . '\\Writer\\' . $this->parameters['writer_class'];
        if (!class_exists($writerClass)) {
            throw new UserException(sprintf("Writer class '%s' doesn't exist", $writerClass));
        }

        return new $writerClass($this->parameters['db'], $logger);
    }
}
