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
        Schema::table('device_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('device_settings', 'incoming_sms_enabled')) {
                $table->boolean('incoming_sms_enabled')
                    ->default(true)
                    ->after('transaction_processing_enabled');
            }

            if (! Schema::hasColumn('device_settings', 'incoming_sms_allow_all_senders')) {
                $table->boolean('incoming_sms_allow_all_senders')
                    ->default(false)
                    ->after('incoming_sms_enabled');
            }

            if (! Schema::hasColumn('device_settings', 'incoming_sms_sim_slot')) {
                $table->string('incoming_sms_sim_slot', 20)
                    ->default('all')
                    ->after('incoming_sms_allow_all_senders');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_settings', function (Blueprint $table): void {
            foreach (['incoming_sms_sim_slot', 'incoming_sms_allow_all_senders', 'incoming_sms_enabled'] as $column) {
                if (Schema::hasColumn('device_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
