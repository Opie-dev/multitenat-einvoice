<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A webhook URL is a destination this engine will make server-side requests to,
 * so an unchecked one is an SSRF primitive: `https://169.254.169.254/…` would
 * turn every merchant's webhook config into a read of the host's cloud metadata.
 *
 * The rule therefore demands HTTPS and a host that resolves to a *public*
 * address. `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` is exactly the
 * blocklist we want — 10/8, 172.16/12, 192.168/16, fc00::/7 (private) plus
 * 0.0.0.0/8, 127/8, 169.254/16, 240/4, ::1, ::, ::ffff:0:0/96 and fe80::/10
 * (reserved) — so it is used rather than a hand-rolled CIDR table.
 *
 * `einvoice.webhooks.allow_local_urls` re-opens the loopback carve-out for local
 * development and the test suite; it is off by default and must stay off in
 * production.
 */
class PublicHttpsUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            $fail('Webhook URLs must include a host.');

            return;
        }

        if (config('einvoice.webhooks.allow_local_urls') === true) {
            if ($scheme !== 'https' && ! in_array($host, ['localhost', '127.0.0.1'], true)) {
                $fail('Webhook URLs must use HTTPS.');
            }

            return;
        }

        if ($scheme !== 'https') {
            $fail('Webhook URLs must use HTTPS.');

            return;
        }

        // parse_url keeps the brackets around an IPv6 literal.
        $literal = trim($host, '[]');
        $ip = filter_var($literal, FILTER_VALIDATE_IP) !== false ? $literal : gethostbyname($host);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            // gethostbyname() hands back the hostname unchanged when it cannot resolve it.
            $fail('Webhook URL host could not be resolved to an IP address.');

            return;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            $fail('Webhook URLs must point at a public address.');
        }
    }
}
