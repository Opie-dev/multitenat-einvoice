<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('einvoice:refresh-reference-data');
    }
}
