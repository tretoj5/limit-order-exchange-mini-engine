<?php

namespace App\Models;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'symbol', 'side', 'price', 'amount', 'status'])]
class Order extends Model
{
    protected function casts(): array
    {
        return [
            'side' => OrderSide::class,
            'price' => 'decimal:8',
            'amount' => 'decimal:8',
            'status' => OrderStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
