<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Exception;

class ApplicationException extends \Exception
{
    /** @var array */
    protected $data;

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, array $data = [])
    {
        $this->setData($data);
        parent::__construct($message, $code, $previous);
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
