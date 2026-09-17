<?php

namespace App\Core;

class NotFoundException extends ApiException
{
    public function __construct(string $message = 'Not found')
    {
        parent::__construct($message, 404, 'NOT_FOUND');
    }
}
