<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Placeholder model — Task 7 replaces this file with the full credential/certificate implementation.
 *
 * @property string $id
 * @property string $issuer_id
 * @property string|null $cert_subject
 * @property string|null $cert_serial
 * @property string|null $cert_fingerprint
 * @property Carbon|null $cert_not_before
 * @property Carbon|null $cert_not_after
 */
class IssuerSecret extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $guarded = ['id'];

    public function hasCredentials(): bool
    {
        return false;
    }

    public function hasCertificate(): bool
    {
        return false;
    }
}
