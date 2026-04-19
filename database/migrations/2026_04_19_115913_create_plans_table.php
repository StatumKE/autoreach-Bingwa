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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('backend_plan_id')->nullable();
            $table->string('code')->index();
            $table->string('name');
            $table->string('type'); // 'time_unlimited' or 'usage_pack'
            $table->integer('price')->default(0);
            $table->integer('duration_days')->nullable();
            $table->integer('ussd_requests_included')->nullable();
            $table->integer('ussd_counter')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
