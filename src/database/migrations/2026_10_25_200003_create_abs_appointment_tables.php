<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abs_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('abs_businesses')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('abs_branches')->nullOnDelete();
            $table->foreignId('staff_id')->constrained('abs_staff')->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('abs_services')->nullOnDelete();
            $table->foreignId('customer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('booking_reference', 20)->unique();
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone', 40)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('blocked_starts_at');
            $table->dateTime('blocked_ends_at');
            $table->string('timezone', 64);
            $table->string('status', 20);
            $table->string('approval_mode', 20);
            $table->string('payment_status', 20)->default('unpaid');
            $table->string('payment_method', 20)->default('pay_later');
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('deposit_minor')->default(0);
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->text('customer_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->string('source', 20)->default('online');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('hold_expires_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('reminder_sent_at')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'blocked_starts_at', 'blocked_ends_at'], 'abs_appt_staff_block_idx');
            $table->index(['business_id', 'starts_at']);
            $table->index(['business_id', 'status']);
            $table->index(['status', 'hold_expires_at']);
            $table->index(['status', 'starts_at', 'reminder_sent_at'], 'abs_appt_reminder_idx');
            $table->index('customer_email');
        });

        Schema::create('abs_appointment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('abs_appointments')->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('abs_services')->nullOnDelete();
            $table->string('service_name');
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedBigInteger('line_total_minor');
            $table->timestamps();
        });

        Schema::create('abs_appointment_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('abs_appointments')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 20);
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['appointment_id', 'created_at']);
        });

        Schema::create('abs_appointment_reschedule_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('abs_appointments')->cascadeOnDelete();
            $table->dateTime('old_starts_at');
            $table->dateTime('old_ends_at');
            $table->foreignId('old_staff_id')->nullable()->constrained('abs_staff')->nullOnDelete();
            $table->dateTime('new_starts_at');
            $table->dateTime('new_ends_at');
            $table->foreignId('new_staff_id')->nullable()->constrained('abs_staff')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 20);
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('abs_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('abs_businesses')->cascadeOnDelete();
            $table->string('name', 30);
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->unique(['business_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abs_sequences');
        Schema::dropIfExists('abs_appointment_reschedule_histories');
        Schema::dropIfExists('abs_appointment_status_histories');
        Schema::dropIfExists('abs_appointment_items');
        Schema::dropIfExists('abs_appointments');
    }
};
