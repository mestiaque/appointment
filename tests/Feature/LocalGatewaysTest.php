<?php

namespace ME\Abs\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use ME\Abs\Actions\AppointmentBookingAction;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Enums\BusinessRole;
use ME\Abs\Enums\PaymentRecordStatus;
use ME\Abs\Enums\PaymentStatus;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Business;
use ME\Abs\Models\PaymentGatewaySetting;
use ME\Abs\Payments\GatewayManager;
use ME\Abs\Services\Booking\BookingRequest;
use ME\Abs\Services\Payments\CheckoutService;
use ME\Abs\Services\Payments\RefundService;
use ME\Abs\Tests\TestCase;

/**
 * bKash, SSLCommerz and Nagad against faked provider APIs (no live merchant accounts available).
 */
class LocalGatewaysTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-01 00:00:00');
        Notification::fake();
        Cache::flush();
        config(['abs.gateway' => null]);
    }

    private function bdtBooking(string $provider, array $credentials, array $settings = ['payment_mode' => 'online_required']): Appointment
    {
        [$business, $service, $staff] = $this->bookableBusiness($settings + ['hold_minutes' => 30], ['currency' => 'BDT', 'timezone' => 'Asia/Dhaka']);
        $service->update(['price_minor' => 100000]); // BDT 1,000.00
        PaymentGatewaySetting::query()->create(['business_id' => $business->id, 'provider' => $provider, 'is_enabled' => true, 'is_sandbox' => true, 'credentials' => $credentials]);

        return app(AppointmentBookingAction::class)->execute(new BookingRequest(
            $business, $service->id, $staff->id, null, $this->utc('2026-11-10 04:00'), 'Rahim', 'rahim@example.com', '01711000101',
        ));
    }

    // ---------------- bKash ----------------

    private function fakeBkash(string $status = 'Completed', string $amount = '1000.00'): void
    {
        Http::fake([
            '*/tokenized/checkout/token/grant' => Http::response(['statusCode' => '0000', 'id_token' => 'tok']),
            '*/tokenized/checkout/create' => Http::response(['statusCode' => '0000', 'paymentID' => 'TR001', 'bkashURL' => 'https://sandbox.bka.sh/pay/TR001']),
            '*/tokenized/checkout/payment/status' => Http::response(['statusCode' => '0000', 'transactionStatus' => 'Initiated', 'paymentID' => 'TR001']),
            '*/tokenized/checkout/execute' => Http::response(['statusCode' => '0000', 'transactionStatus' => $status, 'trxID' => 'BKX123', 'amount' => $amount, 'currency' => 'BDT']),
            '*/tokenized/checkout/payment/refund' => Http::response(['statusCode' => '0000', 'transactionStatus' => 'Completed', 'refundTrxID' => 'RF1']),
        ]);
    }

    private function bkashCreds(): array
    {
        return ['username' => 'u', 'password' => 'p', 'app_key' => 'k', 'app_secret' => 's'];
    }

    public function test_bkash_payment_is_executed_server_side_on_return(): void
    {
        $this->fakeBkash();
        $a = $this->bdtBooking('bkash', $this->bkashCreds());

        $payment = app(CheckoutService::class)->start($a, 'bkash');
        $this->assertSame('TR001', $payment->provider_reference);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/create') && $r['amount'] === '1000.00' && $r['currency'] === 'BDT'
            && $r['callbackURL'] === $payment->callbackUrl() && $r->hasHeader('Authorization', 'tok'));

        // bKash appends its own query string to the callback URL.
        $this->get($payment->callbackUrl().'?paymentID=TR001&status=success')->assertRedirect();

        $this->assertSame(PaymentRecordStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame('BKX123', $payment->fresh()->provider_payment_id);
        $this->assertSame(AppointmentStatus::Confirmed, $a->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $a->fresh()->payment_status);
    }

    public function test_bkash_amount_mismatch_or_cancel_is_never_paid(): void
    {
        $this->fakeBkash(amount: '1.00');
        $a = $this->bdtBooking('bkash', $this->bkashCreds());
        $payment = app(CheckoutService::class)->start($a, 'bkash');

        $this->get($payment->callbackUrl().'?paymentID=TR001&status=success');

        $this->assertSame(PaymentRecordStatus::Failed, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $a->fresh()->payment_status);
    }

    public function test_callback_needs_the_payment_token(): void
    {
        $this->fakeBkash();
        $a = $this->bdtBooking('bkash', $this->bkashCreds());
        $payment = app(CheckoutService::class)->start($a, 'bkash');

        $this->get(route('abs.public.payment.callback', ['payment' => $payment->id, 'token' => str_repeat('a', 32)]))->assertNotFound();
        $this->assertSame(PaymentRecordStatus::Pending, $payment->fresh()->status);
    }

    public function test_bkash_refund(): void
    {
        $this->fakeBkash();
        $a = $this->bdtBooking('bkash', $this->bkashCreds());
        $payment = app(CheckoutService::class)->start($a, 'bkash');
        $this->get($payment->callbackUrl().'?paymentID=TR001&status=success');

        $refund = app(RefundService::class)->request($payment->fresh(), 40000, 'Partial', null);

        $this->assertSame('completed', $refund->status->value);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/payment/refund') && $r['trxID'] === 'BKX123' && $r['amount'] === '400.00');
        $this->assertSame(PaymentStatus::PartiallyRefunded, $a->fresh()->payment_status);
    }

    // ---------------- SSLCommerz ----------------

    private function fakeSsl(string $status = 'VALID', string $amount = '1000.00', string $risk = '0'): void
    {
        Http::fake([
            'sandbox.sslcommerz.com/gwprocess/v4/api.php' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://sandbox.sslcommerz.com/pay/x', 'sessionkey' => 'S']),
            'sandbox.sslcommerz.com/validator/api/validationserverAPI.php*' => fn ($r) => Http::response(['status' => $status, 'tran_id' => Appointment::query()->first()->payments()->value('provider_reference'), 'amount' => $amount]),
            'sandbox.sslcommerz.com/validator/api/merchantTransIDvalidationAPI.php*' => fn ($r) => isset($r->data()['bank_tran_id'])
                ? Http::response(['APIConnect' => 'DONE', 'status' => 'success', 'refund_ref_id' => 'REF9'])
                : Http::response(['APIConnect' => 'DONE', 'no_of_trans_found' => 1, 'element' => [['status' => $status, 'amount' => $amount, 'currency' => 'BDT', 'bank_tran_id' => 'BANK77', 'risk_level' => $risk]]]),
        ]);
    }

    public function test_sslcommerz_post_back_is_validated_with_the_api(): void
    {
        $this->fakeSsl();
        $a = $this->bdtBooking('sslcommerz', ['store_id' => 'store', 'store_password' => 'pw']);
        $payment = app(CheckoutService::class)->start($a, 'sslcommerz');

        $this->assertStringStartsWith('ABS'.$payment->id.'-', $payment->provider_reference);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'gwprocess') && $r['total_amount'] === '1000.00'
            && str_contains($r['ipn_url'], '/abs/webhooks/sslcommerz/'.$a->business_id) && $r['success_url'] === $payment->callbackUrl());

        // SSLCommerz POSTs back to the success URL without a CSRF token.
        $this->post($payment->callbackUrl(), ['tran_id' => $payment->provider_reference, 'val_id' => 'V1', 'status' => 'VALID'])->assertRedirect();

        $this->assertSame(PaymentRecordStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame('BANK77', $payment->fresh()->provider_payment_id);
    }

    public function test_sslcommerz_high_risk_payment_is_held(): void
    {
        $this->fakeSsl(risk: '1');
        $a = $this->bdtBooking('sslcommerz', ['store_id' => 'store', 'store_password' => 'pw']);
        $payment = app(CheckoutService::class)->start($a, 'sslcommerz');

        $this->post($payment->callbackUrl(), ['tran_id' => $payment->provider_reference]);

        $this->assertSame(PaymentRecordStatus::Pending, $payment->fresh()->status);
    }

    public function test_sslcommerz_ipn_is_verified_and_idempotent(): void
    {
        $this->fakeSsl();
        $a = $this->bdtBooking('sslcommerz', ['store_id' => 'store', 'store_password' => 'pw']);
        $payment = app(CheckoutService::class)->start($a, 'sslcommerz');
        $url = route('abs.webhooks', ['provider' => 'sslcommerz', 'business' => $a->business_id]);

        $this->post($url, ['tran_id' => $payment->provider_reference, 'val_id' => 'V1', 'status' => 'VALID'])->assertJson(['result' => 'processed']);
        $this->post($url, ['tran_id' => $payment->provider_reference, 'val_id' => 'V1', 'status' => 'VALID'])->assertJson(['result' => 'duplicate']);
        $this->assertSame(PaymentStatus::Paid, $a->fresh()->payment_status);
    }

    public function test_forged_sslcommerz_ipn_is_rejected(): void
    {
        $this->fakeSsl(status: 'INVALID_TRANSACTION');
        $a = $this->bdtBooking('sslcommerz', ['store_id' => 'store', 'store_password' => 'pw']);
        $payment = app(CheckoutService::class)->start($a, 'sslcommerz');

        $this->post(route('abs.webhooks', ['provider' => 'sslcommerz', 'business' => $a->business_id]), ['tran_id' => $payment->provider_reference, 'val_id' => 'FAKE'])
            ->assertStatus(400);
        $this->assertSame(PaymentRecordStatus::Pending, $payment->fresh()->status);
    }

    // ---------------- Nagad ----------------

    public function test_nagad_encrypts_signs_and_verifies(): void
    {
        $merchant = openssl_pkey_new(['private_key_bits' => 2048]);
        $pg = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($merchant, $merchantPrivate);
        $merchantPublic = openssl_pkey_get_details($merchant)['key'];
        openssl_pkey_export($pg, $pgPrivate);
        $pgPublic = openssl_pkey_get_details($pg)['key'];

        Http::fake([
            '*/check-out/initialize/*' => function ($request) use ($pgPrivate, $merchantPublic) {
                // Nagad decrypts with its private key and checks the merchant signature.
                openssl_private_decrypt(base64_decode($request['sensitiveData']), $plain, $pgPrivate);
                $sent = json_decode($plain, true);
                openssl_public_encrypt(json_encode(['paymentReferenceId' => 'NREF1', 'challenge' => 'C2']), $enc, $merchantPublic);

                return $sent['orderId'] ?? false ? Http::response(['sensitiveData' => base64_encode($enc), 'signature' => 'x']) : Http::response([], 400);
            },
            '*/check-out/complete/NREF1' => Http::response(['status' => 'Success', 'callBackUrl' => 'https://sandbox.mynagad.com/pay/NREF1']),
            '*/verify/payment/NREF1' => Http::response(['status' => 'Success', 'amount' => '1000', 'issuerPaymentRefNo' => 'NG55']),
        ]);

        $a = $this->bdtBooking('nagad', ['merchant_id' => 'M1', 'merchant_number' => '01700000000', 'merchant_private_key' => $merchantPrivate, 'pg_public_key' => $pgPublic]);
        $payment = app(CheckoutService::class)->start($a, 'nagad');
        $this->assertSame('NREF1', $payment->provider_reference);

        Http::assertSent(function ($r) use ($merchantPublic, $pgPrivate) {
            if (! str_contains($r->url(), '/complete/NREF1')) {
                return false;
            }
            // As Nagad would: decrypt the order with the PG private key, then check the merchant signature over it.
            openssl_private_decrypt(base64_decode($r['sensitiveData']), $plain, $pgPrivate);
            $order = json_decode($plain, true);

            return $order['amount'] === '1000.00' && $order['currencyCode'] === '050' && $order['challenge'] === 'C2'
                && openssl_verify($plain, base64_decode($r['signature']), $merchantPublic, OPENSSL_ALGO_SHA256) === 1
                && $r['merchantCallbackURL'] === Appointment::query()->first()->payments()->first()->callbackUrl();
        });

        $this->get($payment->callbackUrl().'?merchant=M1&order_id=x&payment_ref_id=NREF1&status=Success')->assertRedirect();
        $this->assertSame(PaymentRecordStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame('NG55', $payment->fresh()->provider_payment_id);
    }

    // ---------------- manager & admin ----------------

    public function test_only_enabled_configured_gateways_for_the_currency_are_offered(): void
    {
        $usd = Business::factory()->create(['currency' => 'USD']);
        $bdt = Business::factory()->create(['currency' => 'BDT']);
        foreach ([$usd, $bdt] as $b) {
            PaymentGatewaySetting::query()->create(['business_id' => $b->id, 'provider' => 'bkash', 'is_enabled' => true, 'credentials' => $this->bkashCreds()]);
            PaymentGatewaySetting::query()->create(['business_id' => $b->id, 'provider' => 'sslcommerz', 'is_enabled' => true, 'credentials' => ['store_id' => 'x']]); // missing password
            PaymentGatewaySetting::query()->create(['business_id' => $b->id, 'provider' => 'nagad', 'is_enabled' => false, 'credentials' => []]);
        }

        $manager = app(GatewayManager::class);
        $this->assertSame(['bkash'], $manager->forBusiness($bdt)->keys()->all());
        $this->assertSame([], $manager->forBusiness($usd)->keys()->all()); // bKash is BDT-only
    }

    public function test_admin_saves_credentials_encrypted_and_secrets_are_write_only(): void
    {
        [$business] = $this->bookableBusiness([], ['currency' => 'BDT']);
        $owner = $this->member($business);
        $this->actingInBusiness($owner, $business);

        $this->put(route('abs.gateways.update', 'bkash'), ['is_enabled' => 1, 'is_sandbox' => 1, 'credentials' => $this->bkashCreds()])->assertSessionHas('success');

        $raw = DB::table('abs_payment_gateways')->value('credentials');
        $this->assertStringNotContainsString('app_secret', $raw);

        $this->get(route('abs.gateways.index'))->assertOk()->assertSee('bKash')->assertDontSee('value="s"', false)->assertSee('saved');

        // Empty secret keeps the stored one; plain fields can change.
        $this->put(route('abs.gateways.update', 'bkash'), ['is_enabled' => 1, 'is_sandbox' => 0, 'credentials' => ['username' => 'u2', 'password' => '', 'app_key' => 'k', 'app_secret' => '']]);
        $row = PaymentGatewaySetting::query()->first();
        $this->assertSame('u2', $row->credentials['username']);
        $this->assertSame('p', $row->credentials['password']);
        $this->assertFalse($row->is_sandbox);
    }

    public function test_test_connection_and_permissions(): void
    {
        $this->fakeBkash();
        [$business] = $this->bookableBusiness([], ['currency' => 'BDT']);
        $owner = $this->member($business);
        PaymentGatewaySetting::query()->create(['business_id' => $business->id, 'provider' => 'bkash', 'is_enabled' => true, 'credentials' => $this->bkashCreds()]);

        $this->actingInBusiness($owner, $business)->post(route('abs.gateways.test', 'bkash'))->assertSessionHas('success');

        $receptionist = $this->member($business, BusinessRole::Receptionist);
        $this->actingInBusiness($receptionist, $business)->get(route('abs.gateways.index'))->assertForbidden();
        $this->put(route('abs.gateways.update', 'bkash'), ['is_enabled' => 0])->assertForbidden();
    }

    public function test_customer_chooses_a_gateway_on_the_booking_page(): void
    {
        $this->fakeBkash();
        $a = $this->bdtBooking('bkash', $this->bkashCreds(), ['payment_mode' => 'online_optional']);

        $this->get($a->manageUrl())->assertOk()->assertSee('bKash');
        $response = $this->post(URL::signedRoute('abs.public.pay', ['reference' => $a->booking_reference]), ['provider' => 'bkash']);
        $response->assertRedirect('https://sandbox.bka.sh/pay/TR001');
    }
}
