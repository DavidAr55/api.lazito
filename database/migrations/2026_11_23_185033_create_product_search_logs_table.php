<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // One row per call to a paid provider (cache hits do not appear), so spending can be audited and capped.
        Schema::create('product_search_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('query', 80);
            $table->unsignedSmallInteger('credits');
            $table->unsignedSmallInteger('result_count');
            $table->timestamps();

            $table->index(['provider', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_search_logs');
    }
};
