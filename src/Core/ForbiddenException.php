<?php

namespace App\Core;

class ForbiddenException extends ApiException
{
    public function __construct(string $message = 'Forbidden')
    {
        parent::__construct($message, 403, 'FORBIDDEN');
    }
}
