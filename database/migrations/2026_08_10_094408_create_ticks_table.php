<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('symbol_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 18, 8);
            $table->decimal('day_change', 18, 8)->nullable();
            $table->decimal('day_change_pct', 9, 4)->nullable();
            $table->string('source', 16);
            $table->timestampTz('quoted_at', 6);
            $table->timestampTz('received_at', 6);

            // No timestamps(): ticks are immutable, and an append-heavy table
            // should not carry two columns nothing ever reads.
            $table->index(['symbol_id', 'quoted_at']);
            $table->index('quoted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticks');
    }
};
