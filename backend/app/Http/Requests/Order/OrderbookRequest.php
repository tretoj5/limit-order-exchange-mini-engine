<?php

namespace App\Http\Requests\Order;

use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderbookRequest extends FormRequest
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
            'symbol' => ['required', 'string', Rule::in(Asset::supportedSymbols())],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('symbol'))) {
            $this->merge(['symbol' => strtoupper($this->input('symbol'))]);
        }
    }
}
