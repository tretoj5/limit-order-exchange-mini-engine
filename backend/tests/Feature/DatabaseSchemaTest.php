<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_uses_the_isolated_postgres_test_database(): void
    {
        $this->assertSame('exchange_test', DB::connection()->getDatabaseName());
        $this->assertSame('db_test', config('database.connections.pgsql.host'));
    }

    public function test_users_cannot_have_a_negative_usd_balance(): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'name' => 'Invalid Balance',
            'email' => 'invalid-balance@example.test',
            'password' => 'hashed-password',
            'balance' => '-0.00000001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_assets_cannot_have_negative_amounts(): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Asset Owner',
            'email' => 'asset-owner@example.test',
            'password' => 'hashed-password',
            'balance' => '0.00000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('assets')->insert([
            'user_id' => $userId,
            'symbol' => 'BTC',
            'amount' => '-0.00000001',
            'locked_amount' => '0.00000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_assets_cannot_exceed_the_total_supported_precision(): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Asset Limit Owner',
            'email' => 'asset-limit-owner@example.test',
            'password' => 'hashed-password',
            'balance' => '0.00000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('assets')->insert([
            'user_id' => $userId,
            'symbol' => 'BTC',
            'amount' => '99999999999999999999.99999999',
            'locked_amount' => '0.00000001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_orders_require_a_supported_side_and_positive_values(): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Order Owner',
            'email' => 'order-owner@example.test',
            'password' => 'hashed-password',
            'balance' => '0.00000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('orders')->insert([
            'user_id' => $userId,
            'symbol' => 'BTC',
            'side' => 'hold',
            'price' => '1.00000000',
            'amount' => '1.00000000',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
