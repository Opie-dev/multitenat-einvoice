<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_codes', function (Blueprint $table) {
            $table->id();
            $table->string('set', 40);
            $table->string('code', 20);
            $table->string('description', 500);
            $table->json('extra')->nullable();
            $table->string('version', 20);
            $table->timestamps();
            $table->unique(['set', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_codes');
    }
};
