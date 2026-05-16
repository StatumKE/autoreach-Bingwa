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
        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('remote_subscription_id')->nullable()->after('backend_plan_id');
            $table->timestamp('remote_purchase_synced_at')->nullable()->after('remote_subscription_id');
            $table->json('remote_purchase_response')->nullable()->after('remote_purchase_synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn([
                'remote_subscription_id',
                'remote_purchase_synced_at',
                'remote_purchase_response',
            ]);
        });
    }
};
