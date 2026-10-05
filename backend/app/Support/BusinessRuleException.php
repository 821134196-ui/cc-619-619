<?php

namespace App\Support;

use RuntimeException;

class BusinessRuleException extends RuntimeException
{
    public function __construct(string $message, public int $status = 422)
    {
        parent::__construct($message);
    }
}
