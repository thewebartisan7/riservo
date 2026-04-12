<?php

use App\Enums\AssignmentStrategy;
use App\Enums\ConfirmationMode;
use App\Enums\PaymentMode;
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
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('logo')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('timezone')->default('Europe/Zurich');
            $table->string('payment_mode')->default(PaymentMode::Offline->value);
            $table->string('confirmation_mode')->default(ConfirmationMode::Auto->value);
            $table->boolean('allow_collaborator_choice')->default(true);
            $table->unsignedInteger('cancellation_window_hours')->default(24);
            $table->string('assignment_strategy')->default(AssignmentStrategy::FirstAvailable->value);
            $table->json('reminder_hours')->default('[]');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
