<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Member = 'member';
    case Vendor = 'vendor';
}
