<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

class NoTenantContext extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No tenant context is bound for this operation.');
    }
}
