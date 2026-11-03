<?php

namespace ME\Abs\Services\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use ME\Abs\Enums\RefundStatus;
use ME\Abs\Exceptions\PaymentException;
use ME\Abs\Models\Payment;
use ME\Abs\Models\Refund;
use ME\Abs\Payments\GatewayManager;
use ME\Abs\Services\Notifications\AppointmentNotifier;
use ME\Models\User;

/**
 * Refund lifecycle: requested -> pending -> completed | failed.
 * Cash/manual payments complete immediately (staff confirm they returned the money).
 * Online payments are sent to the provider after the refund row is committed; the provider call is never
 * made inside a database transaction.
 */
class RefundService
{
    public function __construct(
        private PaymentLedger $ledger,
        private GatewayManager $gateways,
        private AppointmentNotifier $notifier,
    ) {}

    /**
     * @param  bool  $outsideSystem  staff already returned the money outside this system (e.g. from the gateway's merchant portal)
     */
    public function request(Payment $payment, int $amountMinor, ?string $reason, ?User $actor, bool $outsideSystem = false): Refund
    {
        $refund = DB::transaction(function () use ($payment, $amountMinor, $reason, $actor) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($amountMinor < 1 || $amountMinor > $locked->refundableMinor()) {
                throw new PaymentException('Refund amount must be between 1 and the refundable amount.');
            }

            return Refund::query()->create([
                'business_id' => $locked->business_id,
                'payment_id' => $locked->getKey(),
                'amount_minor' => $amountMinor,
                'currency' => $locked->currency,
                'status' => RefundStatus::Requested,
                'reason' => $reason ? mb_substr($reason, 0, 255) : null,
                'requested_by_user_id' => $actor?->getKey(),
            ]);
        });

        if ($outsideSystem || in_array($payment->provider, ['cash', 'manual'], true)) {
            return $this->applyStatus($refund, RefundStatus::Completed, null, 'manual');
        }

        $gateway = $this->gateways->driver($payment->provider, $payment->business);
        if (! $gateway || ! $gateway->supportsRefunds()) {
            return $this->applyStatus($refund, RefundStatus::Failed, null, 'system', 'Payment provider is not configured for refunds.');
        }

        $result = $gateway->refund($payment->fresh(), $refund);

        return $this->applyStatus($refund, $result->status, $result->providerReference, 'provider', $result->failureReason);
    }

    /**
     * Move a refund forward. Idempotent and monotonic: completed/failed refunds never change again,
     * and the money is applied to the payment exactly once.
     */
    public function applyStatus(Refund $refund, RefundStatus $status, ?string $providerReference, string $source, ?string $failureReason = null): Refund
    {
        $changed = false;

        $refund = DB::transaction(function () use ($refund, $status, $providerReference, $source, $failureReason, &$changed) {
            /** @var Refund $locked */
            $locked = Refund::query()->whereKey($refund->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [RefundStatus::Completed, RefundStatus::Failed], true) || $locked->status === $status) {
                if ($providerReference && ! $locked->provider_reference) {
                    $locked->forceFill(['provider_reference' => $providerReference])->save();
                }

                return $locked;
            }

            $locked->forceFill([
                'status' => $status,
                'provider_reference' => $providerReference ?? $locked->provider_reference,
                'processed_at' => in_array($status, [RefundStatus::Completed, RefundStatus::Failed], true) ? CarbonImmutable::now() : null,
                'failure_reason' => $failureReason ? mb_substr($failureReason, 0, 255) : null,
            ])->save();

            if ($status === RefundStatus::Completed) {
                $this->ledger->applyCompletedRefund($locked->payment, $locked->amount_minor, $locked->provider_reference, $source);
            } elseif ($status === RefundStatus::Failed) {
                $this->ledger->log($locked->payment, 'refund', 'failed', $locked->amount_minor, $locked->provider_reference, $source);
            }

            $changed = true;

            return $locked;
        });

        if ($changed) {
            $this->notifier->refundUpdatedAfterCommit($refund);
        }

        return $refund;
    }
}
