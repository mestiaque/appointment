<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abs_service_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('abs_businesses')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'is_active']);
        });

        Schema::create('abs_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('abs_businesses')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('abs_service_categories')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);
            $table->string('deposit_type', 20)->default('none');
            $table->unsignedBigInteger('deposit_value')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'is_active']);
        });

        Schema::create('abs_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('abs_businesses')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('title')->nullable();
            $table->text('bio')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('accepts_bookings')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'is_active']);
            $table->unique(['business_id', 'user_id']);
        });

        Schema::create('abs_staff_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('abs_staff')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('abs_services')->cascadeOnDelete();
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->timestamps();

            $table->unique(['staff_id', 'service_id']);
            $table->index('service_id');
        });

        Schema::create('abs_branch_staff', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained('abs_branches')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('abs_staff')->cascadeOnDelete();
            $table->primary(['branch_id', 'staff_id']);
            $table->index('staff_id');
        });

        Schema::create('abs_staff_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('abs_staff')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['staff_id', 'weekday']);
        });

        Schema::create('abs_staff_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('abs_staff')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('label')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'weekday']);
        });

        Schema::create('abs_staff_time_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('abs_staff')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'starts_at', 'ends_at']);
        });

        Schema::create('abs_business_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('abs_businesses')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('abs_branches')->cascadeOnDelete();
            $table->date('date');
            $table->string('name');
            $table->timestamps();

            $table->index(['business_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abs_business_holidays');
        Schema::dropIfExists('abs_staff_time_off');
        Schema::dropIfExists('abs_staff_breaks');
        Schema::dropIfExists('abs_staff_schedules');
        Schema::dropIfExists('abs_branch_staff');
        Schema::dropIfExists('abs_staff_services');
        Schema::dropIfExists('abs_staff');
        Schema::dropIfExists('abs_services');
        Schema::dropIfExists('abs_service_categories');
    }
};
