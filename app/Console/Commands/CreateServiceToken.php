<?php

namespace App\Console\Commands;

use App\Models\ServiceToken;
use Illuminate\Console\Command;

class CreateServiceToken extends Command
{
    protected $signature = 'einvoice:service-token {name : Service name, e.g. catalog} {--ability=* : Abilities (default *)}';

    protected $description = 'Create a service token for an internal Billplz system. The plaintext is shown once.';

    public function handle(): int
    {
        /** @var array<int, string|null> $rawAbilities */
        $rawAbilities = $this->option('ability');
        $abilities = array_values(array_filter(
            $rawAbilities,
            static fn (?string $ability): bool => $ability !== null && $ability !== '',
        ));
        if ($abilities === []) {
            $abilities = ['*'];
        }

        /** @var string $name */
        $name = $this->argument('name');
        ['plaintext' => $plaintext] = ServiceToken::generate($name, $abilities);
        $this->info('Service token created. Store it now; it will not be shown again:');
        $this->line($plaintext);

        return self::SUCCESS;
    }
}
