<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'Demo Buyer',
                'email' => 'demo@exchange.test',
                'password' => 'password',
                'balance' => '100000.00000000',
                'assets' => [
                    'BTC' => '0.50000000',
                    'ETH' => '5.00000000',
                ],
            ],
            [
                'name' => 'Demo Seller',
                'email' => 'seller@exchange.test',
                'password' => 'password',
                'balance' => '100000.00000000',
                'assets' => [
                    'BTC' => '2.00000000',
                    'ETH' => '20.00000000',
                ],
            ],
            [
                'name' => 'Demo Trader',
                'email' => 'trader@exchange.test',
                'password' => 'password',
                'balance' => '100000.00000000',
                'assets' => [
                    'BTC' => '1.00000000',
                    'ETH' => '10.00000000',
                ],
            ],
        ];

        foreach ($users as $attributes) {
            $user = User::query()->firstOrNew(['email' => $attributes['email']]);
            $user->name = $attributes['name'];
            $user->password = Hash::make($attributes['password']);
            $user->balance = $attributes['balance'];
            $user->save();

            foreach ($attributes['assets'] as $symbol => $amount) {
                $asset = $user->assets()->firstOrNew(['symbol' => $symbol]);
                $asset->amount = $amount;
                $asset->locked_amount = '0.00000000';
                $asset->save();
            }
        }
    }
}
