<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            // Cashier resolves the FK from the billable model via $this->getForeignKey().
            // For our billable model (Business), that resolves to `business_id`.
            // The published migration ships `user_id`; we rename to keep the column
            // honest about what it points at.
            $table->foreignId('business_id');
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'stripe_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
