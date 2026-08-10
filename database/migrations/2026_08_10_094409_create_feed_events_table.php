<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_events', function (Blueprint $table): void {
            $table->id();
            $table->string('level', 8);
            $table->string('type', 64);
            $table->text('message');
            $table->jsonb('context')->nullable();
            $table->timestampTz('occurred_at');

            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_events');
    }
};
