<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->integer('quantity');
            $table->string('idempotency_key', 255);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['product_id', 'idempotency_key']);
        });

        DB::statement('
            ALTER TABLE reservations
            ADD CONSTRAINT reservations_quantity_positive
            CHECK (quantity > 0)
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
