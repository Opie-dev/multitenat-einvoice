<?php

namespace App\Lhdn;

enum LhdnErrorKind: string
{
    case Transient = 'transient';
    case Auth = 'auth';
    case Terminal = 'terminal';
    case Breaker = 'breaker';
}
