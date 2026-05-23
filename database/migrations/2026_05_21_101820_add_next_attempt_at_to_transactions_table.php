<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('next_attempt_at')
                ->nullable()
                ->after('occurred_at');
            $table->index(['user_id', 'status', 'next_attempt_at']);
        });

        DB::table('transactions')
            ->whereNull('next_attempt_at')
            ->update([
                'next_attempt_at' => DB::raw('occurred_at'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status', 'next_attempt_at']);
            $table->dropColumn('next_attempt_at');
        });
    }
};
