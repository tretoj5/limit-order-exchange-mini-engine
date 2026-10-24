<?php

namespace App\Http\Requests\Order;

use App\Models\Asset;
use App\Services\MatchingEngine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|array>>
     */
    public function rules(): array
    {
        return [
            'symbol' => ['bail', 'required', 'string', Rule::in(Asset::supportedSymbols())],
            'side' => ['bail', 'required', 'string', Rule::in(['buy', 'sell'])],
            'price' => ['bail', 'required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/', function ($attribute, $value, $fail) {
                if (bccomp($value, '0', 8) <= 0) {
                    $fail('The price must be greater than zero.');

                    return;
                }

                $amount = $this->input('amount');
                if (
                    is_string($amount)
                    && preg_match('/^\d{1,20}(?:\.\d{1,8})?$/', $amount) === 1
                ) {
                    $limitVolume = bcmul($amount, $value, MatchingEngine::SCALE);
                    if (bccomp($limitVolume, '0', MatchingEngine::SCALE) <= 0) {
                        $fail('The order notional must be at least 0.00000001 USD at scale 8.');

                        return;
                    }

                    if ($this->input('side') !== 'buy') {
                        return;
                    }

                    $reserve = app(MatchingEngine::class)->reservedUsd($value, $amount);
                    if (bccomp($reserve, MatchingEngine::MAX_USD_BALANCE, MatchingEngine::SCALE) > 0) {
                        $fail('The buy order reserve exceeds the supported USD precision.');
                    }
                }
            }],
            'amount' => ['bail', 'required', 'string', 'regex:/^\d{1,20}(?:\.\d{1,8})?$/', function ($attribute, $value, $fail) {
                if (bccomp($value, '0', 8) <= 0) {
                    $fail('The amount must be greater than zero.');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'symbol.in' => 'The symbol must be one of: '.implode(', ', Asset::supportedSymbols()),
            'side.in' => 'The side must be either buy or sell.',
            'price.regex' => 'The price must be a valid decimal string with up to 8 decimal places.',
            'amount.regex' => 'The amount must be a valid decimal string with up to 8 decimal places.',
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
