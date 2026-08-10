<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('symbols', function (Blueprint $table): void {
            $table->id();
            $table->string('ticker', 32)->unique();
            $table->string('name', 128);
            $table->string('asset_type', 16);
            $table->string('exchange', 32)->nullable();
            $table->string('currency', 8);
            $table->unsignedTinyInteger('price_decimals')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('symbols');
    }
};
