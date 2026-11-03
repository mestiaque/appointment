<?php

namespace ME\Abs\Services\Booking;

use Illuminate\Support\Collection;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Models\Branch;
use ME\Abs\Models\Business;
use ME\Abs\Models\Service;
use ME\Abs\Models\Staff;

/**
 * Checks that business, branch, service and staff belong together and are bookable, and resolves the
 * staff-specific price and duration. Every id a client sends is checked against the business here.
 */
class BookingEligibilityService
{
    public function assertBusinessBookable(Business $business): void
    {
        if (! $business->isActive()) {
            throw new BookingException('This business is not accepting bookings.');
        }
    }

    public function resolveBranch(Business $business, ?int $branchId): ?Branch
    {
        if (! $branchId) {
            return null;
        }

        $branch = $business->branches()->whereKey($branchId)->first();

        if (! $branch || ! $branch->is_active) {
            throw new BookingException('The selected branch is not available.');
        }

        return $branch;
    }

    public function resolveService(Business $business, int $serviceId): Service
    {
        $service = $business->services()->whereKey($serviceId)->first();

        if (! $service || ! $service->is_active) {
            throw new BookingException('The selected service is not available.');
        }

        if ($service->duration_minutes < 1) {
            throw new BookingException('The selected service has an invalid duration.');
        }

        return $service;
    }

    public function offer(Business $business, Service $service, int $staffId, ?Branch $branch): ServiceOffer
    {
        $offer = $this->eligibleOffers($business, $service, $branch)->first(fn (ServiceOffer $o) => $o->staff->getKey() === $staffId);

        if (! $offer) {
            throw new BookingException('The selected staff member does not offer this service.');
        }

        return $offer;
    }

    /**
     * Bookable staff of the business who offer the service (and work at the branch, when one is given), ordered by id.
     * Staff with no branch assignment work at every branch.
     *
     * @return Collection<int, ServiceOffer>
     */
    public function eligibleOffers(Business $business, Service $service, ?Branch $branch): Collection
    {
        return Staff::query()
            ->where('business_id', $business->getKey())
            ->bookable()
            ->whereHas('services', fn ($q) => $q->whereKey($service->getKey()))
            ->when($branch, fn ($q) => $q->where(fn ($q) => $q
                ->whereDoesntHave('branches')
                ->orWhereHas('branches', fn ($q) => $q->whereKey($branch->getKey()))))
            ->with(['services' => fn ($q) => $q->whereKey($service->getKey())])
            ->orderBy('id')
            ->get()
            ->map(function (Staff $staff) use ($service) {
                $pivot = $staff->services->first()?->pivot;

                return new ServiceOffer(
                    $service,
                    $staff,
                    (int) ($pivot?->duration_minutes ?: $service->duration_minutes),
                    (int) ($pivot?->price_minor ?? $service->price_minor),
                );
            })
            ->values();
    }
}
