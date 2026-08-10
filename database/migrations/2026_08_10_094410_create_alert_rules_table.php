<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('symbol_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 16);
            $table->string('condition', 8);
            $table->decimal('threshold', 18, 8);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('cooldown_seconds')->default(60);
            $table->timestampTz('last_fired_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'symbol_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
