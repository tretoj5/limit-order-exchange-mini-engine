<?php

use App\Models\Asset;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{id}', function ($user, string $id): bool {
    return $user->id === (int) $id;
});

Broadcast::channel('market.{symbol}', function ($user, string $symbol): bool {
    return in_array($symbol, Asset::supportedSymbols(), true);
});
