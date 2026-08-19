<?php

namespace App\Console\Commands;

use App\Models\ReferenceCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RefreshReferenceData extends Command
{
    protected $signature = 'einvoice:refresh-reference-data {--path= : Directory containing <set>.json files (default database/reference)}';

    protected $description = 'Import/upsert LHDN reference code lists from JSON files.';

    public function handle(): int
    {
        $dir = $this->option('path') ?: database_path('reference');
        $total = 0;
        foreach (ReferenceCode::SETS as $set) {
            $file = rtrim($dir, '/\\').DIRECTORY_SEPARATOR."{$set}.json";
            if (! is_file($file)) {
                $this->warn("skip {$set}: {$file} not found");

                continue;
            }
            /** @var array{version:string, items: array<int, array{code:string, description:string, extra?:array<string,mixed>}>} $json */
            $json = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $rows = array_map(fn (array $item) => [
                'set' => $set,
                'code' => $item['code'],
                'description' => $item['description'],
                'extra' => isset($item['extra']) ? json_encode($item['extra']) : null,
                'version' => $json['version'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $json['items']);
            foreach (array_chunk($rows, 500) as $chunk) {
                ReferenceCode::upsert($chunk, ['set', 'code'], ['description', 'extra', 'version', 'updated_at']);
            }
            Cache::forget("reference:{$set}");
            $total += count($rows);
            $this->info(sprintf('%-22s %5d rows (v%s)', $set, count($rows), $json['version']));
        }
        $this->info("Done: {$total} rows.");

        return self::SUCCESS;
    }
}
