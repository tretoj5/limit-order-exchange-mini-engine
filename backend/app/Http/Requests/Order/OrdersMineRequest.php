<?php

namespace App\Http\Requests\Order;

use App\Enums\OrderStatus;
use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrdersMineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|object>>
     */
    public function rules(): array
    {
        return [
            'symbol' => ['sometimes', 'required', 'string', Rule::in(Asset::supportedSymbols())],
            'side' => ['sometimes', 'required', 'string', Rule::in(['buy', 'sell'])],
            'status' => ['sometimes', 'required', 'integer', Rule::in(array_map(
                static fn (OrderStatus $status): int => $status->value,
                OrderStatus::cases(),
            ))],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if (is_string($this->input('symbol'))) {
            $normalized['symbol'] = strtoupper($this->input('symbol'));
        }

        if (is_string($this->input('side'))) {
            $normalized['side'] = strtolower($this->input('side'));
        }

        $this->merge($normalized);
    }
}
