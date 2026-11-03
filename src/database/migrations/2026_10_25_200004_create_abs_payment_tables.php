<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abs_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('abs_businesses')->restrictOnDelete();
            $table->foreignId('appointment_id')->constrained('abs_appointments')->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('purpose', 20);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 20);
            $table->string('provider_reference')->nullable();
            $table->string('provider_payment_id')->nullable();
            $table->text('checkout_url')->nullable();
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_reference']);
            $table->index(['business_id', 'status', 'paid_at']);
            $table->index(['appointment_id', 'status']);
            $table->index('provider_payment_id');
        });

        Schema::create('abs_payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('abs_payments')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('status', 20);
            $table->unsignedBigInteger('amount_minor');
            $table->string('provider_reference')->nullable();
            $table->string('source', 20);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['payment_id', 'created_at']);
        });

        Schema::create('abs_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('abs_businesses')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('abs_payments')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 20);
            $table->string('reason')->nullable();
            $table->string('provider_reference')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('processed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status', 'processed_at']);
            $table->index('provider_reference');
        });

        Schema::create('abs_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('abs_businesses')->restrictOnDelete();
            $table->foreignId('appointment_id')->unique()->constrained('abs_appointments')->restrictOnDelete();
            $table->string('number', 30);
            $table->dateTime('issued_at');
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_minor');
            $table->unsignedBigInteger('tax_minor');
            $table->unsignedBigInteger('total_minor');
            $table->timestamps();

            $table->unique(['business_id', 'number']);
        });

        Schema::create('abs_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('event_id');
            $table->string('type', 100);
            $table->string('status', 20);
            $table->char('payload_hash', 64);
            $table->string('error')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
        });

        Schema::create('abs_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->nullable()->constrained('abs_appointments')->nullOnDelete();
            $table->string('notification', 60);
            $table->string('channel', 30);
            $table->string('recipient');
            $table->string('dedupe_key')->unique();
            $table->string('status', 20);
            $table->string('error')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abs_notification_logs');
        Schema::dropIfExists('abs_webhook_events');
        Schema::dropIfExists('abs_invoices');
        Schema::dropIfExists('abs_refunds');
        Schema::dropIfExists('abs_payment_transactions');
        Schema::dropIfExists('abs_payments');
    }
};
