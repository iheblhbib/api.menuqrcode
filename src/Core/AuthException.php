<?php

namespace App\Core;

class AuthException extends ApiException
{
    public function __construct(string $message = 'Unauthenticated', string $errorCode = 'UNAUTHENTICATED')
    {
        parent::__construct($message, 401, $errorCode);
    }
}
