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
        Schema::create('exclusions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('group_id')->constrained()->cascadeOnDelete();
            // One row per pair, in both directions: neither gives to the other. Ids are stored sorted.
            $table->foreignUlid('participant_a_id')->constrained('participants')->cascadeOnDelete();
            $table->foreignUlid('participant_b_id')->constrained('participants')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['participant_a_id', 'participant_b_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exclusions');
    }
};
