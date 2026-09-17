<?php

namespace App\Core;

class ValidationException extends ApiException
{
    public function __construct(array $fields, string $message = 'Validation failed')
    {
        parent::__construct($message, 422, 'VALIDATION_ERROR', $fields);
    }
}
