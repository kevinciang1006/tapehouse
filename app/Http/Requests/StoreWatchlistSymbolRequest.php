<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWatchlistSymbolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $watchlistId = $this->user()?->watchlist?->id;

        return [
            'symbol_id' => [
                'required',
                'integer',
                Rule::exists('symbols', 'id')->where('is_active', true),
                Rule::unique('watchlist_symbols', 'symbol_id')
                    ->where('watchlist_id', $watchlistId),
            ],
        ];
    }
}
