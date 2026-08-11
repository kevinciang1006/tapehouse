<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AlertCondition;
use App\Enums\AlertMetric;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAlertRuleRequest extends FormRequest
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
            'symbol_id' => ['sometimes', 'integer', Rule::exists('symbols', 'id')],
            'metric' => ['sometimes', Rule::enum(AlertMetric::class)],
            'condition' => ['sometimes', Rule::enum(AlertCondition::class)],
            'threshold' => ['sometimes', 'numeric'],
            'is_active' => ['sometimes', 'boolean'],
            'cooldown_seconds' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
