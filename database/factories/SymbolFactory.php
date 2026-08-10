<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AssetType;
use App\Models\Symbol;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Symbol> */
class SymbolFactory extends Factory
{
    protected $model = Symbol::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ticker' => mb_strtoupper($this->faker->unique()->lexify('????')),
            'name' => $this->faker->company(),
            'asset_type' => AssetType::Stock,
            'exchange' => 'NASDAQ',
            'currency' => 'USD',
            'price_decimals' => 2,
            'is_active' => true,
        ];
    }
}
