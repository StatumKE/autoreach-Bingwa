<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ussd_callback_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('callback_token', 64)->unique();
            $table->string('transaction_key', 64)->index();
            $table->unsignedBigInteger('transaction_id')->nullable()->index();
            $table->string('status', 32);
            $table->text('message')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ussd_callback_deliveries');
    }
};
