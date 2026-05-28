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
        Schema::create('device_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('operator_identity', 120);
            $table->string('primary_transaction_sim', 20)->default('slot_1');
            $table->string('sms_auto_reply_sim', 20)->default('slot_1');
            $table->string('app_interface_mode', 20)->default('express');
            $table->boolean('auto_reschedule_rejected')->default(true);
            $table->string('retry_tomorrow_at', 20)->nullable();
            $table->unsignedSmallInteger('ussd_timeout_seconds')->default(60);
            $table->boolean('intelligent_auto_retry')->default(true);
            $table->unsignedSmallInteger('retry_interval_minutes')->default(1);
            $table->unsignedSmallInteger('max_attempts')->default(2);
            $table->boolean('retry_network_issues')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'app_interface_mode']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_settings');
    }
};
