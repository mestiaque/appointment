<?php

namespace ME\Abs\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use ME\Abs\Actions\AppointmentBookingAction;
use ME\Abs\Actions\AppointmentStateMachine;
use ME\Abs\Enums\ActorType;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Enums\BusinessRole;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Models\Branch;
use ME\Abs\Models\Business;
use ME\Abs\Models\Service;
use ME\Abs\Models\Staff;
use ME\Abs\Services\Booking\BookingEligibilityService;
use ME\Abs\Services\Booking\BookingRequest;
use ME\Abs\Services\Booking\SlotGenerator;
use ME\Abs\Services\Notifications\AppointmentNotifier;
use ME\Abs\Services\Payments\PaymentLedger;
use ME\Models\User;

/**
 * Demo data for a doctor's chamber / clinic (Bangladesh: Asia/Dhaka, BDT, Friday weekly off).
 * All names are fictional. No medical information is stored — customer notes stay generic.
 *
 * php artisan db:seed --class="ME\Abs\Database\Seeders\AbsClinicDemoSeeder"
 */
class AbsClinicDemoSeeder extends Seeder
{
    private const PATIENTS = [
        ['Abdul Karim', '01711000101'], ['Rokeya Begum', '01811000102'], ['Mizanur Rahman', '01911000103'],
        ['Shirin Akter', '01611000104'], ['Habibur Rahman', '01511000105'], ['Nasima Khatun', '01711000106'],
        ['Rafiqul Islam', '01811000107'], ['Sharmin Sultana', '01911000108'], ['Kamrul Hasan', '01611000109'],
        ['Fatema Tuz Zohra', '01711000110'], ['Jahangir Alam', '01811000111'], ['Taslima Nasrin', '01911000112'],
        ['Shafiqul Islam', '01611000113'], ['Mahfuza Khanam', '01511000114'], ['Anisur Rahman', '01711000115'],
        ['Rumana Afroz', '01811000116'], ['Delwar Hossain', '01911000117'], ['Shahnaz Parvin', '01611000118'],
        ['Mostafizur Rahman', '01711000119'], ['Laila Arjumand', '01811000120'], ['Zahid Hasan', '01911000121'],
        ['Sabina Yasmin', '01611000122'], ['Ariful Haque', '01711000123'], ['Nazma Akter', '01811000124'],
    ];

    public function run(AppointmentBookingAction $booking, AppointmentStateMachine $states, PaymentLedger $ledger, AppointmentNotifier $notifier): void
    {
        // Demo seeding must never e-mail anyone.
        $notifier->muted(fn () => $this->seed($booking, $states, $ledger));
    }

    private function seed(AppointmentBookingAction $booking, AppointmentStateMachine $states, PaymentLedger $ledger): void
    {
        $this->call(AbsRolesSeeder::class);

        $owner = User::query()->orderBy('id')->first();
        $business = $this->business($owner);
        [$dhanmondi, $uttara] = $this->branches($business);
        $services = $this->services($business);
        $doctors = $this->doctors($business, $services, $dhanmondi->id, $uttara->id);

        $business->holidays()->create(['date' => '2026-12-16', 'name' => 'Victory Day']);
        $business->holidays()->create(['date' => '2026-11-03', 'name' => 'Uttara branch maintenance', 'branch_id' => $uttara->id]);
        $doctors['gynae']->timeOff()->create([
            'starts_at' => CarbonImmutable::parse('2026-11-08 00:00', 'Asia/Dhaka')->utc(),
            'ends_at' => CarbonImmutable::parse('2026-11-11 00:00', 'Asia/Dhaka')->utc(),
            'reason' => 'Medical conference',
        ]);

        $this->appointments($business, $doctors, $services, $booking, $states, $ledger, $owner);

        $this->command?->info('Clinic demo: /'.config('abs.public_path').'/'.$business->slug);
    }

    private function business(?User $owner): Business
    {
        $business = Business::query()->create([
            'name' => 'City Care Medical Centre',
            'slug' => 'city-care-medical-centre',
            'owner_user_id' => $owner?->id,
            'description' => 'Specialist doctors\' chambers for medicine, child health, women\'s health, cardiology and dental care. Book your serial online and pay at the counter.',
            'email' => 'appointments@citycare.example.com',
            'phone' => '+880 1700-000000',
            'address' => 'House 12, Road 7, Dhanmondi, Dhaka 1205',
            'timezone' => 'Asia/Dhaka',
            'currency' => 'BDT',
            'status' => 'active',
        ]);

        if ($owner) {
            $business->members()->create(['user_id' => $owner->id, 'role' => BusinessRole::Owner, 'is_active' => true]);
        }

        $business->bookingSetting()->create([
            'approval_mode' => 'instant',
            'pending_blocks_slot' => true,
            'min_notice_minutes' => 60,
            'max_days_ahead' => 30,
            'slot_interval_minutes' => 10,
            'allow_guest_booking' => true,
            'require_customer_phone' => true,
            'allow_customer_cancellation' => true,
            'cancellation_notice_minutes' => 180,
            'allow_customer_reschedule' => true,
            'reschedule_notice_minutes' => 180,
            'payment_mode' => 'pay_later',
            'hold_minutes' => 30,
            'tax_rate_bps' => 0,
            'reminder_minutes_before' => 1440,
        ]);

        return $business;
    }

    /**
     * @return array{0: Branch, 1: Branch}
     */
    private function branches(Business $business): array
    {
        return [
            $business->branches()->create(['name' => 'Dhanmondi Chamber', 'address' => 'House 12, Road 7, Dhanmondi, Dhaka', 'phone' => '+880 1700-000001', 'sort_order' => 1]),
            $business->branches()->create(['name' => 'Uttara Chamber', 'address' => 'Plot 5, Sector 7, Uttara, Dhaka', 'phone' => '+880 1700-000002', 'sort_order' => 2]),
        ];
    }

    /**
     * @return array<string, Service>
     */
    private function services(Business $business): array
    {
        $categories = collect(['Medicine', 'Child Health', 'Women\'s Health', 'Cardiology', 'Dental Care', 'Diagnostics'])
            ->mapWithKeys(fn ($name, $i) => [$name => $business->serviceCategories()->create(['name' => $name, 'sort_order' => $i + 1])]);

        $make = fn (string $key, string $category, string $name, int $minutes, int $taka, array $extra = []) => [
            $key => $business->services()->create($extra + [
                'category_id' => $categories[$category]->id,
                'name' => $name,
                'duration_minutes' => $minutes,
                'price_minor' => $taka * 100,
                'is_active' => true,
            ]),
        ];

        return array_merge(
            $make('medicine_new', 'Medicine', 'Medicine consultation (new patient)', 20, 1000, ['description' => 'First visit with a medicine specialist.']),
            $make('medicine_follow', 'Medicine', 'Medicine follow-up (within 30 days)', 10, 500, ['description' => 'Report review or follow-up visit.']),
            $make('child', 'Child Health', 'Child specialist consultation', 20, 1000, ['description' => 'For children up to 16 years.']),
            $make('gynae', 'Women\'s Health', 'Gynaecology consultation', 25, 1200),
            $make('cardio', 'Cardiology', 'Cardiology consultation', 20, 1500),
            $make('ecg', 'Diagnostics', 'ECG', 15, 400, ['buffer_after_minutes' => 5, 'description' => 'Resting 12-lead ECG. Report the same day.']),
            $make('dental_check', 'Dental Care', 'Dental check-up', 20, 800),
            $make('dental_scaling', 'Dental Care', 'Scaling & polishing', 45, 2500, ['buffer_after_minutes' => 10, 'deposit_type' => 'percent', 'deposit_value' => 20]),
        );
    }

    /**
     * Weekdays: 0 Sun … 6 Sat. Friday (5) is the weekly off day.
     *
     * @param  array<string, Service>  $services
     * @return array<string, Staff>
     */
    private function doctors(Business $business, array $services, int $dhanmondi, int $uttara): array
    {
        $satToThu = [6, 0, 1, 2, 3, 4];
        $plan = [
            'medicine' => ['Dr. Farhana Rahman', 'MBBS, FCPS (Medicine) — Medicine Specialist', ['medicine_new', 'medicine_follow'], [$dhanmondi],
                [[$satToThu, '17:00', '21:00']], [[$satToThu, '19:00', '19:20']]],
            'child' => ['Dr. Tanvir Hasan', 'MBBS, DCH — Child Specialist', ['child'], [$dhanmondi, $uttara],
                [[[6, 0, 1, 2, 3], '10:00', '13:00'], [[6, 0, 1, 2, 3], '17:00', '20:00']], []],
            'gynae' => ['Dr. Nusrat Jahan', 'MBBS, FCPS (Obs & Gynae) — Gynaecologist', ['gynae'], [$uttara],
                [[[0, 1, 2, 3, 4], '16:00', '20:00']], []],
            'cardio' => ['Dr. Mahmudul Karim', 'MBBS, MD (Cardiology) — Cardiologist', ['cardio'], [$dhanmondi],
                [[[6, 1, 3], '18:00', '22:00']], []],
            'dental' => ['Dr. Sadia Islam', 'BDS, PGT (Dental) — Dental Surgeon', ['dental_check', 'dental_scaling'], [$uttara],
                [[$satToThu, '10:00', '14:00']], [[$satToThu, '12:00', '12:30']]],
            'ecg' => ['ECG Room', 'Diagnostics — walk-in ECG', ['ecg'], [$dhanmondi],
                [[$satToThu, '09:00', '13:00'], [$satToThu, '16:00', '21:00']], []],
        ];

        $doctors = [];
        foreach ($plan as $key => [$name, $title, $serviceKeys, $branches, $hours, $breaks]) {
            $staff = $business->staff()->create([
                'name' => $name, 'title' => $title, 'is_active' => true, 'accepts_bookings' => true,
                'email' => Str::slug($name, '.').'@citycare.example.com',
            ]);
            $staff->services()->attach(collect($serviceKeys)->map(fn ($k) => $services[$k]->id));
            $staff->branches()->attach($branches);

            foreach ($hours as [$days, $from, $to]) {
                foreach ($days as $day) {
                    $staff->schedules()->create(['weekday' => $day, 'start_time' => $from, 'end_time' => $to]);
                }
            }
            foreach ($breaks as [$days, $from, $to]) {
                foreach ($days as $day) {
                    $staff->breaks()->create(['weekday' => $day, 'start_time' => $from, 'end_time' => $to, 'label' => 'Prayer / tea break']);
                }
            }

            $doctors[$key] = $staff;
        }

        return $doctors;
    }

    /**
     * About two weeks of past visits (completed, no-show, cancelled, paid in cash) and two weeks of upcoming ones.
     *
     * @param  array<string, Staff>  $doctors
     * @param  array<string, Service>  $services
     */
    private function appointments(Business $business, array $doctors, array $services, AppointmentBookingAction $booking, AppointmentStateMachine $states, PaymentLedger $ledger, ?User $actor): void
    {
        $pairs = [
            ['medicine', 'medicine_new'], ['medicine', 'medicine_follow'], ['child', 'child'], ['gynae', 'gynae'],
            ['cardio', 'cardio'], ['ecg', 'ecg'], ['dental', 'dental_check'], ['dental', 'dental_scaling'],
        ];
        $today = CarbonImmutable::now('Asia/Dhaka')->startOfDay();
        $patient = 0;

        for ($offset = -14; $offset <= 14; $offset++) {
            $date = $today->addDays($offset)->toDateString();

            foreach ($pairs as $i => [$doctorKey, $serviceKey]) {
                // Fewer bookings further ahead, a full chamber in the recent past.
                if (($offset + $i) % ($offset > 3 ? 3 : 2) !== 0) {
                    continue;
                }

                $start = $this->freeSlot($business, $doctors[$doctorKey], $services[$serviceKey]->id, $date, $patient);
                if (! $start) {
                    continue;
                }

                [$name, $phone] = self::PATIENTS[$patient % count(self::PATIENTS)];
                $patient++;

                try {
                    $appointment = $booking->execute(new BookingRequest(
                        business: $business,
                        serviceId: $services[$serviceKey]->id,
                        staffId: $doctors[$doctorKey]->id,
                        branchId: null,
                        startsAt: $start,
                        customerName: $name,
                        customerEmail: Str::slug($name, '.').'@example.com',
                        customerPhone: $phone,
                        customerNotes: $patient % 5 === 0 ? 'Please call before the appointment.' : null,
                        // Online bookings must respect the booking window, so past visits are front-desk entries.
                        source: $start->isPast() || $patient % 3 === 0 ? 'admin' : 'online',
                        actor: $actor,
                    ));
                } catch (BookingException) {
                    continue;
                }

                $this->progress($appointment, $patient, $states, $ledger, $actor);
            }
        }
    }

    /**
     * Past visits move through the real lifecycle; most are completed and paid in cash.
     */
    private function progress($appointment, int $n, AppointmentStateMachine $states, PaymentLedger $ledger, ?User $actor): void
    {
        $past = $appointment->ends_at->isPast();

        if ($n % 11 === 0) {
            $states->transition($appointment, AppointmentStatus::Cancelled, $past ? ActorType::Staff : ActorType::Customer, $past ? $actor : null, 'Patient could not come');

            return;
        }

        if (! $past) {
            // A few upcoming patients paid an advance at the counter.
            if ($n % 4 === 0 && $actor) {
                $ledger->recordManual($appointment, intdiv($appointment->total_minor, 2), 'cash', $actor, 'Advance at counter');
            }

            return;
        }

        if ($n % 7 === 0) {
            $states->transition($appointment, AppointmentStatus::NoShow, ActorType::Staff, $actor);

            return;
        }

        foreach ([AppointmentStatus::CheckedIn, AppointmentStatus::InProgress, AppointmentStatus::Completed] as $status) {
            $states->transition($appointment, $status, ActorType::Staff, $actor);
        }

        if ($actor && $n % 6 !== 0) {
            $ledger->recordManual($appointment, $appointment->total_minor, $n % 4 === 0 ? 'manual' : 'cash', $actor, $n % 4 === 0 ? 'bKash' : null);
        }
    }

    /**
     * A free start for this doctor on the date, spread across the chamber hours.
     */
    private function freeSlot(Business $business, Staff $doctor, int $serviceId, string $date, int $seed): ?CarbonImmutable
    {
        $eligibility = app(BookingEligibilityService::class);
        $offers = $eligibility->eligibleOffers($business, $eligibility->resolveService($business, $serviceId), null)
            ->filter(fn ($o) => $o->staff->id === $doctor->id)->values();

        $slots = array_keys(app(SlotGenerator::class)->forDate($business, $offers, $date));
        if ($slots === []) {
            return null;
        }

        return CarbonImmutable::parse($slots[($seed * 3) % count($slots)]);
    }
}
