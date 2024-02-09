<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Exception;

use Exception;
use Keboola\CommonExceptions\UserExceptionInterface;

class InvalidDatabaseHostException extends Exception implements UserExceptionInterface
{
}
