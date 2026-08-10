<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlist_symbols', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('watchlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('symbol_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['watchlist_id', 'symbol_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist_symbols');
    }
};
