<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable(['user_id', 'symbol', 'amount', 'locked_amount'])]
class Asset extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'locked_amount' => 'decimal:8',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function setSymbolAttribute(string $value): void
    {
        $symbol = Str::upper($value);
        $supportedSymbols = self::supportedSymbols();

        if (! in_array($symbol, $supportedSymbols, true)) {
            throw new InvalidArgumentException("Unsupported symbol: {$symbol}. Supported: ".implode(', ', $supportedSymbols));
        }

        $this->attributes['symbol'] = $symbol;
    }

    /**
     * @return list<string>
     */
    public static function supportedSymbols(): array
    {
        return config('exchange.symbols', []);
    }
}
