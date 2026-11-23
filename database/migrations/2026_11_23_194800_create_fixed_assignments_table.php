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
        // Pairs the organizer decided by hand: the giver always gives to the receiver.
        Schema::create('fixed_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('group_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('giver_id')->constrained('participants')->cascadeOnDelete();
            $table->foreignUlid('receiver_id')->constrained('participants')->cascadeOnDelete();
            $table->timestamps();

            $table->unique('giver_id');
            $table->unique('receiver_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fixed_assignments');
    }
};
