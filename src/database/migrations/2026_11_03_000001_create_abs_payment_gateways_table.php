<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-business payment gateway credentials, managed from the admin panel.
     * credentials is an encrypted JSON blob (APP_KEY); it is never shown back in the UI.
     */
    public function up(): void
    {
        Schema::create('abs_payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('abs_businesses')->cascadeOnDelete();
            $table->string('provider', 30);
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_sandbox')->default(true);
            $table->text('credentials')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abs_payment_gateways');
    }
};
