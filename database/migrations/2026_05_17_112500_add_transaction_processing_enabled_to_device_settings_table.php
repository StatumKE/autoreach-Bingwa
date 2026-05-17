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
            if (! Schema::hasColumn('device_settings', 'transaction_processing_enabled')) {
                $table->boolean('transaction_processing_enabled')
                    ->default(true)
                    ->after('retry_network_issues');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('device_settings', 'transaction_processing_enabled')) {
                $table->dropColumn('transaction_processing_enabled');
            }
        });
    }
};
