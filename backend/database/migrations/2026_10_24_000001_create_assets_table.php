<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 10);
            $table->decimal('amount', 28, 8)->default('0.00000000');
            $table->decimal('locked_amount', 28, 8)->default('0.00000000');
            $table->timestamps();

            $table->unique(['user_id', 'symbol']);
        });

        DB::statement('ALTER TABLE assets ADD CONSTRAINT assets_amounts_nonnegative CHECK (amount >= 0 AND locked_amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
