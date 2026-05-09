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
        if (! Schema::hasColumn('bingwa_device_registrations', 'last_seen_at')) {
            Schema::table('bingwa_device_registrations', function (Blueprint $table): void {
                $table->timestamp('last_seen_at')->nullable()->after('app_version');
            });
        }

        if (! Schema::hasColumn('bingwa_device_registrations', 'status')) {
            Schema::table('bingwa_device_registrations', function (Blueprint $table): void {
                $table->string('status', 30)->nullable()->after('last_seen_at')->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('bingwa_device_registrations', 'status')) {
            Schema::table('bingwa_device_registrations', function (Blueprint $table): void {
                $table->dropColumn('status');
            });
        }

        if (Schema::hasColumn('bingwa_device_registrations', 'last_seen_at')) {
            Schema::table('bingwa_device_registrations', function (Blueprint $table): void {
                $table->dropColumn('last_seen_at');
            });
        }
    }
};
