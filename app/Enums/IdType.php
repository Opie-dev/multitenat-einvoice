<?php

namespace App\Enums;

enum IdType: string
{
    case Brn = 'BRN';
    case Nric = 'NRIC';
    case Passport = 'PASSPORT';
    case Army = 'ARMY';
}
