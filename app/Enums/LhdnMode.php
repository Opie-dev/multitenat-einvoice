<?php

namespace App\Enums;

enum LhdnMode: string
{
    case Intermediary = 'intermediary';
    case OwnCredentials = 'own_credentials';
}
