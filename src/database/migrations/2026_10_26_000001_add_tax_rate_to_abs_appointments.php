<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot of the tax rate used at booking time, so later discounts are taxed the same way
     * even if the business changes its tax setting.
     */
    public function up(): void
    {
        Schema::table('abs_appointments', function (Blueprint $table) {
            $table->unsignedInteger('tax_rate_bps')->default(0)->after('tax_minor');
        });

        // Existing bookings: derive the rate from the stored amounts (exact enough for re-pricing).
        DB::table('abs_appointments')
            ->where('tax_minor', '>', 0)
            ->whereRaw('subtotal_minor > discount_minor')
            ->update(['tax_rate_bps' => DB::raw('ROUND(tax_minor * 10000 / (subtotal_minor - discount_minor))')]);
    }

    public function down(): void
    {
        Schema::table('abs_appointments', function (Blueprint $table) {
            $table->dropColumn('tax_rate_bps');
        });
    }
};
