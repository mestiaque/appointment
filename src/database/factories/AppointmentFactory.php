<?php

namespace ME\Abs\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Enums\PaymentStatus;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Staff;

/**
 * For tests that need existing appointments without running the booking action.
 *
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $start = CarbonImmutable::now()->addDays(2)->setTime(10, 0);

        return [
            'staff_id' => Staff::factory(),
            'business_id' => fn (array $attributes) => Staff::query()->find($attributes['staff_id'])->business_id,
            'booking_reference' => 'BK-'.Str::upper(Str::random(8)),
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'starts_at' => $start,
            'ends_at' => $start->addMinutes(30),
            'blocked_starts_at' => $start,
            'blocked_ends_at' => $start->addMinutes(30),
            'timezone' => 'UTC',
            'approval_mode' => 'instant',
            'currency' => 'USD',
            'subtotal_minor' => 5000,
            'total_minor' => 5000,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Appointment $appointment) {
            $appointment->status ??= AppointmentStatus::Confirmed;
            $appointment->payment_status ??= PaymentStatus::Unpaid;
        });
    }

    public function status(AppointmentStatus $status): static
    {
        return $this->afterMaking(fn (Appointment $appointment) => $appointment->status = $status);
    }

    public function at(CarbonImmutable $start, int $minutes = 30): static
    {
        return $this->state(fn () => [
            'starts_at' => $start,
            'ends_at' => $start->addMinutes($minutes),
            'blocked_starts_at' => $start,
            'blocked_ends_at' => $start->addMinutes($minutes),
        ]);
    }
}
