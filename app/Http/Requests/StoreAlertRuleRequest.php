<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AlertCondition;
use App\Enums\AlertMetric;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAlertRuleRequest extends FormRequest
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
        return [
            'symbol_id' => ['required', 'integer', Rule::exists('symbols', 'id')],
            'metric' => ['required', Rule::enum(AlertMetric::class)],
            'condition' => ['required', Rule::enum(AlertCondition::class)],
            'threshold' => ['required', 'numeric'],
            'cooldown_seconds' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
