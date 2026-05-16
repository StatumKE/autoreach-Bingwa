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
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('auto_reply_id')
                ->nullable()
                ->after('processed_at')
                ->constrained('auto_replies')
                ->nullOnDelete();
            $table->string('auto_reply_trigger_condition', 80)->nullable()->after('auto_reply_id')->index();
            $table->text('auto_reply_message')->nullable()->after('auto_reply_trigger_condition');
            $table->string('auto_reply_recipient_phone', 20)->nullable()->after('auto_reply_message')->index();
            $table->string('auto_reply_sim_slot', 20)->nullable()->after('auto_reply_recipient_phone');
            $table->string('auto_reply_status', 20)->nullable()->after('auto_reply_sim_slot')->index();
            $table->unsignedSmallInteger('auto_reply_attempts')->default(0)->after('auto_reply_status');
            $table->timestamp('auto_reply_sent_at')->nullable()->after('auto_reply_attempts');
            $table->timestamp('auto_reply_failed_at')->nullable()->after('auto_reply_sent_at');
            $table->text('auto_reply_failure_reason')->nullable()->after('auto_reply_failed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('auto_reply_id');
            $table->dropColumn([
                'auto_reply_trigger_condition',
                'auto_reply_message',
                'auto_reply_recipient_phone',
                'auto_reply_sim_slot',
                'auto_reply_status',
                'auto_reply_attempts',
                'auto_reply_sent_at',
                'auto_reply_failed_at',
                'auto_reply_failure_reason',
            ]);
        });
    }
};
