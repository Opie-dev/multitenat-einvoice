<?php

function assertMatchesGolden(string $name, array $actual): void
{
    $path = base_path("tests/Fixtures/ubl/{$name}.json");
    $json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n";
    if (! is_file($path) || getenv('UPDATE_GOLDEN') === '1') {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $json);
        expect(true)->toBeTrue();

        return;
    }
    expect($json)->toBe((string) file_get_contents($path), "Golden file {$name}.json differs; run with UPDATE_GOLDEN=1 to regenerate after reviewing the change.");
}
