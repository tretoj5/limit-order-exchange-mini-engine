<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{id}', function ($user, string $id): bool {
    return $user->id === (int) $id;
});
