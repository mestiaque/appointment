<?php

namespace ME\Abs\Services\Payments;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use ME\Abs\Enums\PaymentRecordStatus;
use ME\Abs\Models\Payment;
use ME\Abs\Models\Refund;
use ME\Abs\Models\WebhookEvent;
use ME\Abs\Payments\GatewayEvent;
use ME\Abs\Payments\PaymentGateway;
use Throwable;

/**
 * Processes a verified provider event once.
 *
 * Idempotency: abs_webhook_events has a unique (provider, event_id). A processed event is skipped;
 * a failed one may be retried by the provider. Payment state is always re-read from the provider API
 * before money is recorded, so a forged or stale event body cannot mark anything paid.
 */
class WebhookProcessor
{
    public function __construct(private PaymentLedger $ledger, private RefundService $refunds) {}

    /**
     * @return string processed | duplicate | ignored | failed
     */
    public function handle(PaymentGateway $gateway, GatewayEvent $event): string
    {
        $record = $this->claim($gateway->name(), $event);
        if ($record === null) {
            return 'duplicate';
        }

        try {
            $result = match ($event->kind) {
                GatewayEvent::CHECKOUT_COMPLETED => $this->checkoutCompleted($gateway, $event),
                GatewayEvent::CHECKOUT_FAILED => $this->checkoutEnded($gateway, $event, 'Payment failed at the provider', PaymentRecordStatus::Failed),
                GatewayEvent::CHECKOUT_EXPIRED => $this->checkoutEnded($gateway, $event, 'Checkout expired', PaymentRecordStatus::Cancelled),
                GatewayEvent::REFUND_UPDATED => $this->refundUpdated($gateway, $event),
                default => 'ignored',
            };

            $record->forceFill(['status' => $result, 'processed_at' => now(), 'error' => null])->save();

            return $result;
        } catch (Throwable $e) {
            $record->forceFill(['status' => 'failed', 'error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 250)])->save();
            report($e);

            return 'failed';
        }
    }

    private function claim(string $provider, GatewayEvent $event): ?WebhookEvent
    {
        try {
            return DB::transaction(function () use ($provider, $event) {
                $existing = WebhookEvent::query()->where('provider', $provider)->where('event_id', $event->id)->lockForUpdate()->first();

                if ($existing) {
                    return in_array($existing->status, ['processed', 'ignored', 'unmatched'], true) ? null : $existing;
                }

                return WebhookEvent::query()->create([
                    'provider' => $provider,
                    'event_id' => $event->id,
                    'type' => mb_substr($event->providerType, 0, 100),
                    'status' => 'received',
                    'payload_hash' => $event->payloadHash,
                ]);
            });
        } catch (QueryException) {
            return null; // the same event is being stored by a parallel delivery
        }
    }

    private function checkoutCompleted(PaymentGateway $gateway, GatewayEvent $event): string
    {
        $payment = $this->payment($gateway->name(), $event->reference);
        if (! $payment) {
            return 'unmatched';
        }

        // Capture-on-return providers (PayPal): an approved order is not money yet, so a payment we already
        // cancelled (expired hold, superseded checkout) is not captured at all.
        if ($gateway->capturesOnReturn() && $payment->status !== PaymentRecordStatus::Pending && ! in_array($payment->status, PaymentRecordStatus::captured(), true)) {
            return 'ignored';
        }

        $status = $gateway->retrieveCheckout((string) $payment->provider_reference);
        if (! $status->isPaid()) {
            return 'ignored';
        }

        $this->ledger->markSucceeded($payment, $status, 'webhook');

        return 'processed';
    }

    private function checkoutEnded(PaymentGateway $gateway, GatewayEvent $event, string $reason, PaymentRecordStatus $as): string
    {
        $payment = $this->payment($gateway->name(), $event->reference);
        if (! $payment) {
            return 'unmatched';
        }

        $this->ledger->markFailed($payment, $reason, 'webhook', $as);

        return 'processed';
    }

    private function refundUpdated(PaymentGateway $gateway, GatewayEvent $event): string
    {
        // Match by our own id from metadata first: the event can arrive before provider_reference is saved.
        $refund = $event->localRefundId ? Refund::query()->find($event->localRefundId) : null;
        $refund ??= $event->reference ? Refund::query()->where('provider_reference', $event->reference)->first() : null;
        $belongsToEvent = $refund
            && $refund->payment->provider === $gateway->name()
            && (! $refund->provider_reference || $refund->provider_reference === $event->reference);

        if (! $belongsToEvent || ! $event->refundStatus) {
            return 'unmatched';
        }

        $this->refunds->applyStatus($refund, $event->refundStatus, $event->reference, 'webhook');

        return 'processed';
    }

    private function payment(string $provider, ?string $reference): ?Payment
    {
        return $reference ? Payment::query()->where('provider', $provider)->where('provider_reference', $reference)->first() : null;
    }
}
