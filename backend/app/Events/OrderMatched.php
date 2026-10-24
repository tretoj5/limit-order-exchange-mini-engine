<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderMatched implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    /**
     * @param array{
     *     trade: array{id: int, symbol: string, price: string, amount: string, usd_volume: string, fee: string, side: string},
     *     order: array{id: int, status: int},
     *     balance: string,
     *     assets: array<int, array{symbol: string, amount: string, locked_amount: string}>
     * } $payload
     */
    public function __construct(
        public readonly int $userId,
        private readonly array $payload,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'OrderMatched';
    }

    /**
     * @return array{
     *     trade: array{id: int, symbol: string, price: string, amount: string, usd_volume: string, fee: string, side: string},
     *     order: array{id: int, status: int},
     *     balance: string,
     *     assets: array<int, array{symbol: string, amount: string, locked_amount: string}>
     * }
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
