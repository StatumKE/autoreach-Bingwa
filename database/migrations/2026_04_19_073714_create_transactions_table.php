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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('transaction_id', 100)->unique();
            $table->string('mpesa_code', 50)->nullable()->index();
            $table->string('sender_phone', 20)->index();
            $table->string('sender_name', 120)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('offer_name', 120);
            $table->string('offer_type', 50)->index();
            $table->json('matched_offer')->nullable();
            $table->json('balance')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->string('status', 30)->index();
            $table->string('status_desc', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
