<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case Validated = 'validated';
    case Held = 'held';
    case Queued = 'queued';
    case Submitted = 'submitted';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';
    case AwaitingConsolidation = 'awaiting_consolidation';
    case Consolidated = 'consolidated';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Rejected, self::Consolidated], true);
    }

    public function isHeld(): bool
    {
        return $this === self::Held;
    }
}
