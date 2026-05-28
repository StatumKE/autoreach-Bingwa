<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a composite index that covers the dashboard stats aggregation query.
     *
     * Hot query (consolidated single-pass aggregate):
     *   SELECT
     *     SUM(CASE WHEN status = 'completed' AND DATE(occurred_at) = ? THEN 1 ELSE 0 END),
     *     SUM(CASE WHEN status = 'failed'    AND DATE(occurred_at) = ? THEN 1 ELSE 0 END),
     *     SUM(CASE WHEN status = 'completed' AND DATE(occurred_at) = ? THEN amount ELSE 0 END)
     *   FROM transactions
     *   WHERE user_id = ?
     *     AND occurred_at >= ?
     *
     * Index column order follows the Equality → Range rule:
     *   user_id (equality) → occurred_at (range, date filter)
     *
     * Including `status` and `amount` as covering columns lets SQLite satisfy
     * the CASE/WHEN branches without a table row fetch ("index-only scan").
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'occurred_at', 'status', 'amount'],
                'transactions_dashboard_stats_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_dashboard_stats_index');
        });
    }
};
