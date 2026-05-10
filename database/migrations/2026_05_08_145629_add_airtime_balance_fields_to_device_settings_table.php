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
        if (! Schema::hasColumn('device_settings', 'airtime_balance')) {
            Schema::table('device_settings', function (Blueprint $table): void {
                $table->decimal('airtime_balance', 12, 2)->nullable()->after('updated_at');
            });
        }

        if (! Schema::hasColumn('device_settings', 'airtime_balance_raw_response')) {
            Schema::table('device_settings', function (Blueprint $table): void {
                $table->text('airtime_balance_raw_response')->nullable()->after('airtime_balance');
            });
        }

        if (! Schema::hasColumn('device_settings', 'airtime_balance_checked_at')) {
            Schema::table('device_settings', function (Blueprint $table): void {
                $table->timestamp('airtime_balance_checked_at')->nullable()->after('airtime_balance_raw_response');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ([
            'airtime_balance_checked_at',
            'airtime_balance_raw_response',
            'airtime_balance',
        ] as $column) {
            if (Schema::hasColumn('device_settings', $column)) {
                Schema::table('device_settings', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
