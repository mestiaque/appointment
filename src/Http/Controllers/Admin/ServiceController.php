<?php

namespace ME\Abs\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use ME\Abs\Enums\DepositType;
use ME\Abs\Http\Controllers\Controller;
use ME\Abs\Models\Service;
use ME\Abs\Support\Currency;

class ServiceController extends Controller
{
    public function index(Request $request): View
    {
        $services = $this->business()->services()
            ->with('category')
            ->withCount('staff')
            ->when($request->string('q')->toString(), fn ($q, $term) => $q->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->when($request->integer('category'), fn ($q, $id) => $q->where('category_id', $id))
            ->orderBy('sort_order')->orderBy('name')
            ->paginate($this->pageSize())->withQueryString();

        return view('abs::admin.services.index', [
            'services' => $services,
            'categories' => $this->business()->serviceCategories()->orderBy('name')->get(['id', 'name']),
            'business' => $this->business(),
        ]);
    }

    public function create(): View
    {
        return $this->form(new Service(['is_active' => true, 'duration_minutes' => 30, 'deposit_type' => DepositType::None]));
    }

    public function store(Request $request): RedirectResponse
    {
        $service = $this->business()->services()->create($this->validated($request));
        me_change_log('Service created: '.$service->name, 'abs.service.create')->record([], $service->only(['name', 'duration_minutes', 'price_minor']));

        return redirect()->route('abs.services.index')->with('success', __('abs::abs.saved'));
    }

    public function edit(int $service): View
    {
        return $this->form($this->find($service));
    }

    public function update(Request $request, int $service): RedirectResponse
    {
        $model = $this->find($service);
        $data = $this->validated($request);
        // Existing bookings keep their own snapshot (abs_appointment_items), so price/duration edits never rewrite history.
        me_change_log('Service updated: '.$model->name, 'abs.service.update')->watch($model)->run(fn () => $model->update($data));

        return redirect()->route('abs.services.index')->with('success', __('abs::abs.saved'));
    }

    public function destroy(int $service): RedirectResponse
    {
        $model = $this->find($service);
        me_change_log('Service deleted: '.$model->name, 'abs.service.delete')->watch($model)->delete(fn () => $model->delete());

        return back()->with('success', __('abs::abs.deleted'));
    }

    private function form(Service $service): View
    {
        return view('abs::admin.services.form', [
            'service' => $service,
            'business' => $this->business(),
            'categories' => $this->business()->serviceCategories()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function find(int $id): Service
    {
        return $this->business()->services()->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $business = $this->business();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'integer', Rule::exists('abs_service_categories', 'id')->where('business_id', $business->getKey())],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'price' => ['required', 'string', 'max:20'],
            'buffer_before_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'buffer_after_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'deposit_type' => ['required', Rule::enum(DepositType::class)],
            'deposit_value' => ['nullable', 'string', 'max:20'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        try {
            $price = Currency::toMinor($data['price'], $business->currency);
            $depositValue = match ($data['deposit_type']) {
                DepositType::Fixed->value => Currency::toMinor((string) ($data['deposit_value'] ?? '0'), $business->currency),
                DepositType::Percent->value => (int) ($data['deposit_value'] ?? 0),
                default => 0,
            };
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['price' => $e->getMessage()]);
        }

        if ($data['deposit_type'] === DepositType::Percent->value && ($depositValue < 1 || $depositValue > 100)) {
            throw ValidationException::withMessages(['deposit_value' => 'Deposit percent must be between 1 and 100.']);
        }

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'duration_minutes' => $data['duration_minutes'],
            'price_minor' => $price,
            'buffer_before_minutes' => (int) ($data['buffer_before_minutes'] ?? 0),
            'buffer_after_minutes' => (int) ($data['buffer_after_minutes'] ?? 0),
            'deposit_type' => $data['deposit_type'],
            'deposit_value' => $depositValue,
            'is_active' => $request->boolean('is_active'),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
