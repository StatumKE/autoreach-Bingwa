<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace the previous 3-column partial index with a covering index that
     * eliminates the filesort on the queue-fetch hot path.
     *
     * Hot query:
     *   SELECT * FROM transactions
     *   WHERE  user_id = ?            -- equality
     *     AND  status  = 'queued'     -- equality
     *     AND  (next_attempt_at IS NULL OR next_attempt_at <= NOW())  -- range
     *   ORDER BY id ASC               -- sort
     *   LIMIT 1
     *
     * Column order follows the Equality → Range → Sort rule.
     * Adding `id` as the trailing column lets the engine satisfy ORDER BY
     * entirely from the index, with no additional filesort step.
     *
     * ORDER BY id (auto-increment) is used instead of occurred_at because:
     *   - id is a monotonically increasing surrogate key — always unique
     *   - occurred_at can have ties, forcing a secondary sort anyway
     *   - id is already a clustered key pointer; the index covers it for free
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Drop the old index that lacked the ORDER BY column.
            $table->dropIndex('transactions_user_id_status_next_attempt_at_index');

            // New covering index: equality(user_id, status) → range(next_attempt_at) → sort(id).
            $table->index(
                ['user_id', 'status', 'next_attempt_at', 'id'],
                'transactions_queue_fetch_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_queue_fetch_index');
            $table->index(
                ['user_id', 'status', 'next_attempt_at'],
                'transactions_user_id_status_next_attempt_at_index',
            );
        });
    }
};
