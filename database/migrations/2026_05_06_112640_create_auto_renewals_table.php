<?php

use App\Models\Offer;
use App\Models\User;
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
        Schema::create('auto_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Offer::class)->nullable()->constrained()->nullOnDelete();
            $table->string('customer_phone', 32);
            $table->dateTime('scheduled_for');
            $table->boolean('auto_renew')->default(true);
            $table->unsignedSmallInteger('renew_days')->default(1);
            $table->string('status')->default('scheduled');
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->index(['user_id', 'scheduled_for']);
            $table->index(['user_id', 'status']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auto_renewals');
    }
};
