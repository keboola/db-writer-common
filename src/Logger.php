<?php

declare(strict_types=1);

namespace Keboola\DbWriter;

use Keboola\DbWriter\Logger\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as MonologLogger;

class Logger extends MonologLogger
{
    /**
     * @param string $name
     * @param bool $debug - DEPRECATED - has no effect
     */
    public function __construct(string $name = '', bool $debug = false)
    {
        parent::__construct(
            $name,
            [
                self::getCriticalHandler(),
                self::getErrorHandler(),
                self::getInfoHandler()
            ]
        );
    }

    public static function getErrorHandler(): StreamHandler
    {
        $errorHandler = new StreamHandler('php://stderr');
        $errorHandler->setBubble(false);
        $errorHandler->setLevel(MonologLogger::NOTICE);
        $errorHandler->setFormatter(new LineFormatter("%message%\n"));
        return $errorHandler;
    }
    public static function getInfoHandler(): StreamHandler
    {
        $logHandler = new StreamHandler('php://stdout');
        $logHandler->setBubble(false);
        $logHandler->setLevel(MonologLogger::INFO);
        $logHandler->setFormatter(new LineFormatter("%message%\n"));
        return $logHandler;
    }
    public static function getCriticalHandler(): StreamHandler
    {
        $handler = new StreamHandler('php://stderr');
        $handler->setBubble(false);
        $handler->setLevel(MonologLogger::CRITICAL);
        $handler->setFormatter(new LineFormatter("[%datetime%] %level_name%: %message% %context% %extra%\n"));
        return $handler;
    }
}
