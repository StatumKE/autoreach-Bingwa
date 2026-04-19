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
        Schema::create('bingwa_device_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('hardware_id')->unique();
            $table->text('device_token');
            $table->string('bhc_code', 20)->nullable();
            $table->unsignedBigInteger('backend_device_id')->nullable();
            $table->string('app_type', 50)->nullable();
            $table->string('backend_device_type', 50)->nullable();
            $table->string('connect_device_id', 20)->nullable();
            $table->string('linked_connect_device_id', 20)->nullable();
            $table->string('device_name', 100)->nullable();
            $table->string('app_version', 50)->nullable();
            $table->json('device_info')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bingwa_device_registrations');
    }
};
