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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->integer('initial_stock');
            $table->integer('available_quantity');
            $table->timestamps();
        });

        DB::statement('
            ALTER TABLE products
            ADD CONSTRAINT products_stock_valid
            CHECK (
                initial_stock >= 0
                AND available_quantity >= 0
                AND available_quantity <= initial_stock
            )
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
