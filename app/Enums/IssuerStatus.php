<?php

namespace App\Enums;

enum IssuerStatus: string
{
    case Draft = 'draft';
    case TinVerified = 'tin_verified';
    case Authorized = 'authorized';
    case Active = 'active';
    case Suspended = 'suspended';
}
