<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE assets ADD CONSTRAINT assets_total_precision CHECK (amount + locked_amount <= 99999999999999999999.99999999)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE assets DROP CONSTRAINT assets_total_precision');
    }
};
