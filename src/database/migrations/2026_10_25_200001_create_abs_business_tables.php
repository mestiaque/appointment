<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abs_businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('timezone', 64);
            $table->char('currency', 3);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('abs_business_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('abs_businesses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'user_id']);
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('abs_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('abs_businesses')->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['business_id', 'is_active']);
        });

        Schema::create('abs_booking_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained('abs_businesses')->cascadeOnDelete();
            $table->string('approval_mode', 20)->default('instant');
            $table->boolean('pending_blocks_slot')->default(true);
            $table->unsignedInteger('min_notice_minutes')->default(60);
            $table->unsignedInteger('max_days_ahead')->default(60);
            $table->unsignedSmallInteger('slot_interval_minutes')->default(15);
            $table->boolean('allow_guest_booking')->default(true);
            $table->boolean('require_customer_phone')->default(false);
            $table->boolean('allow_customer_cancellation')->default(true);
            $table->unsignedInteger('cancellation_notice_minutes')->default(1440);
            $table->boolean('allow_customer_reschedule')->default(true);
            $table->unsignedInteger('reschedule_notice_minutes')->default(1440);
            $table->string('payment_mode', 30)->default('pay_later');
            $table->unsignedSmallInteger('hold_minutes')->default(15);
            $table->unsignedInteger('tax_rate_bps')->default(0);
            $table->unsignedInteger('reminder_minutes_before')->default(1440);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abs_booking_settings');
        Schema::dropIfExists('abs_branches');
        Schema::dropIfExists('abs_business_members');
        Schema::dropIfExists('abs_businesses');
    }
};
