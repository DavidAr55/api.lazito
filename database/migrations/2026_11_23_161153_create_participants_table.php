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
        Schema::create('participants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('pin')->nullable();
            $table->string('claim_token', 40)->nullable()->unique();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('revealed_at')->nullable();
            $table->foreignUlid('receiver_id')->nullable()->unique()->constrained('participants')->nullOnDelete();
            $table->timestamps();

            $table->unique(['group_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('participants');
    }
};
