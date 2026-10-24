<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 10);
            $table->string('side', 4);
            $table->decimal('price', 20, 8);
            $table->decimal('amount', 28, 8);
            $table->unsignedSmallInteger('status')->default(1);
            $table->timestamps();

            $table->index(['symbol', 'status', 'side', 'price', 'created_at', 'id'], 'orders_book_lookup_index');
            $table->index(['user_id', 'created_at'], 'orders_user_created_at_index');
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_side_valid CHECK (side IN ('buy', 'sell'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_status_valid CHECK (status IN (1, 2, 3))');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_price_positive CHECK (price > 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
