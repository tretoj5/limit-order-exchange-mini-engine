<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MarketDepthUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public readonly string $symbol,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('market.'.$this->symbol)];
    }

    public function broadcastAs(): string
    {
        return 'MarketDepthUpdated';
    }

    /**
     * @return array{symbol: string}
     */
    public function broadcastWith(): array
    {
        return ['symbol' => $this->symbol];
    }
}
