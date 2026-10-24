<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buy_order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('sell_order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->string('symbol', 10);
            $table->decimal('price', 20, 8);
            $table->decimal('amount', 28, 8);
            $table->decimal('usd_volume', 20, 8);
            $table->decimal('fee', 20, 8);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['symbol', 'created_at']);
        });

        DB::statement('ALTER TABLE trades ADD CONSTRAINT trades_values_valid CHECK (price > 0 AND amount > 0 AND usd_volume > 0 AND fee >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
