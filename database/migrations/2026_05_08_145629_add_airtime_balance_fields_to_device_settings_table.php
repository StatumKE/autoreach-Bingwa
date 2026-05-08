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
        Schema::table('device_settings', function (Blueprint $table) {
            $table->decimal('airtime_balance', 12, 2)->nullable()->after('updated_at');
            $table->text('airtime_balance_raw_response')->nullable()->after('airtime_balance');
            $table->timestamp('airtime_balance_checked_at')->nullable()->after('airtime_balance_raw_response');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_settings', function (Blueprint $table) {
            $table->dropColumn([
                'airtime_balance',
                'airtime_balance_raw_response',
                'airtime_balance_checked_at',
            ]);
        });
    }
};
