<?php

namespace App\Core;

use Exception;

class ApiException extends Exception
{
    protected int $statusCode;
    protected string $errorCode;
    protected array $fields;

    public function __construct(string $message, int $statusCode = 500, string $errorCode = 'SERVER_ERROR', array $fields = [])
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->fields = $fields;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function fields(): array
    {
        return $this->fields;
    }
}
