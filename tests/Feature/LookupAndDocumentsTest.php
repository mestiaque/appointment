<?php

namespace ME\Abs\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use ME\Abs\Enums\BusinessRole;
use ME\Abs\Models\Appointment;
use ME\Abs\Tests\TestCase;

class LookupAndDocumentsTest extends TestCase
{
    private function booking(): Appointment
    {
        CarbonImmutable::setTestNow('2026-11-01 00:00:00');
        [$business, $service, $staff] = $this->bookableBusiness();
        $appointment = Appointment::factory()->at($this->utc('2026-11-05 10:00'))->create([
            'staff_id' => $staff->id, 'business_id' => $business->id, 'service_id' => $service->id, 'customer_phone' => '01711-000101',
        ]);
        $appointment->items()->create(['service_id' => $service->id, 'service_name' => 'Medicine consultation', 'duration_minutes' => 30, 'unit_price_minor' => 5000, 'line_total_minor' => 5000]);

        return $appointment;
    }

    public function test_booking_number_and_matching_phone_open_a_temporary_link(): void
    {
        $a = $this->booking();

        $response = $this->post(route('abs.public.find.lookup'), ['reference' => strtolower(substr($a->booking_reference, 3)), 'phone' => '+880 1711 000101']);
        $response->assertRedirect();
        $this->assertStringContainsString('expires=', $response->headers->get('Location'));
        $this->get($response->headers->get('Location'))->assertOk()->assertSee($a->booking_reference)->assertSee('Prescriptions');
    }

    public function test_wrong_phone_or_number_reveals_nothing_and_is_rate_limited(): void
    {
        $a = $this->booking();

        $wrongPhone = $this->post(route('abs.public.find.lookup'), ['reference' => $a->booking_reference, 'phone' => '01999999999']);
        $unknown = $this->post(route('abs.public.find.lookup'), ['reference' => 'BK-NOTEXIST', 'phone' => '01711000101']);
        $wrongPhone->assertSessionHasErrors('reference');
        $unknown->assertSessionHasErrors(['reference' => 'We could not find a booking with that number and phone. Please check both.']);
        $wrongPhone->assertSessionHasErrors(['reference' => 'We could not find a booking with that number and phone. Please check both.']);

        for ($i = 0; $i < 4; $i++) {
            $this->post(route('abs.public.find.lookup'), ['reference' => $a->booking_reference, 'phone' => '0100000000'.$i]);
        }
        // Correct details are refused once the booking number is locked.
        $this->post(route('abs.public.find.lookup'), ['reference' => $a->booking_reference, 'phone' => '01711000101'])
            ->assertSessionHasErrors('reference');
    }

    public function test_sms_code_is_required_when_sms_is_enabled(): void
    {
        $a = $this->booking();
        Cache::put('abs:lookup-code:'.$a->booking_reference, ['hash' => hash('sha256', '123456'), 'attempts' => 0], now()->addMinutes(10));
        $session = ['abs.lookup' => ['reference' => $a->booking_reference, 'phone' => '•••101']];

        $this->withSession($session)->post(route('abs.public.find.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $response = $this->withSession($session)->post(route('abs.public.find.verify'), ['code' => '123456']);
        $response->assertRedirect();
        $this->assertStringContainsString('/booking/'.$a->booking_reference, $response->headers->get('Location'));
        $this->assertNull(Cache::get('abs:lookup-code:'.$a->booking_reference));
    }

    public function test_staff_upload_documents_privately_and_customer_sees_them(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $a = $this->booking();
        $owner = $this->member($a->business);

        $this->actingInBusiness($owner, $a->business)->post(route('abs.appointments.documents.store', $a->id), [
            'title' => 'Prescription', 'document' => UploadedFile::fake()->create('rx.pdf', 120, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $doc = $a->fresh()->getMedia('documents')->first();
        $this->assertSame('private', $doc->visibility);
        $this->assertSame('local', $doc->disk);
        Storage::disk('local')->assertExists($doc->path);
        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->get(route('me.media.show', $doc->uuid))->assertForbidden();
        auth()->logout();
        $this->get($a->manageUrl())->assertOk()->assertSee('Prescription');
    }

    public function test_document_rules_and_isolation(): void
    {
        Storage::fake('local');
        $a = $this->booking();
        $staffUser = $this->member($a->business, BusinessRole::Staff);
        $a->staff->update(['user_id' => $staffUser->id]);

        $this->actingInBusiness($staffUser, $a->business)->post(route('abs.appointments.documents.store', $a->id), [
            'title' => 'X', 'document' => UploadedFile::fake()->create('rx.pdf', 10, 'application/pdf'),
        ])->assertForbidden();

        [$other] = $this->bookableBusiness();
        $foreign = $this->member($other);
        $this->actingInBusiness($foreign, $other)->post(route('abs.appointments.documents.store', $a->id), [
            'title' => 'X', 'document' => UploadedFile::fake()->create('rx.pdf', 10, 'application/pdf'),
        ])->assertNotFound();

        $owner = $this->member($a->business);
        $this->actingInBusiness($owner, $a->business)->post(route('abs.appointments.documents.store', $a->id), [
            'title' => 'Bad', 'document' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php'),
        ])->assertSessionHasErrors('document');
        $this->assertCount(0, $a->fresh()->getMedia('documents'));
    }

    public function test_print_slip_needs_signature(): void
    {
        $a = $this->booking();

        $this->get(route('abs.public.print', $a->booking_reference))->assertForbidden();
        $this->get(URL::temporarySignedRoute('abs.public.print', now()->addHour(), ['reference' => $a->booking_reference]))
            ->assertOk()->assertSee($a->booking_reference)->assertSee('Amount due');
    }

    public function test_manage_page_shows_pay_at_counter_without_a_gateway(): void
    {
        config(['abs.gateway' => null]);
        $a = $this->booking();

        $this->get($a->manageUrl())->assertOk()->assertSee('at the counter')->assertSee('Print slip');
    }
}
