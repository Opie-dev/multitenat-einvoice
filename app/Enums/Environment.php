<?php

namespace App\Enums;

enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';
}
