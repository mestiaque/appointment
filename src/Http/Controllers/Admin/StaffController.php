<?php

namespace ME\Abs\Http\Controllers\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use ME\Abs\Http\Controllers\Controller;
use ME\Abs\Models\Staff;
use ME\Abs\Support\Currency;
use ME\Abs\Support\LocalTime;
use ME\Models\User;

class StaffController extends Controller
{
    public function index(Request $request): View
    {
        return view('abs::admin.staff.index', [
            'staff' => $this->business()->staff()
                ->withCount(['services', 'schedules'])
                ->with('branches:id,name', 'user:id,name,email')
                ->when($request->string('q')->toString(), fn ($q, $term) => $q->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))
                ->orderBy('sort_order')->orderBy('name')
                ->paginate($this->pageSize())->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return $this->form(new Staff(['is_active' => true, 'accepts_bookings' => true]));
    }

    public function store(Request $request): RedirectResponse
    {
        [$attributes, $services, $branches] = $this->validated($request);

        $staff = DB::transaction(function () use ($attributes, $services, $branches) {
            $staff = $this->business()->staff()->create($attributes);
            $staff->services()->sync($services);
            $staff->branches()->sync($branches);

            return $staff;
        });
        me_change_log('Staff created: '.$staff->name, 'abs.staff.create')->record([], $staff->only(['name', 'email']));

        return redirect()->route('abs.staff.schedule', $staff->getKey())->with('success', __('abs::abs.saved').' Now set the working hours.');
    }

    public function edit(int $staff): View
    {
        return $this->form($this->find($staff)->load('services', 'branches'));
    }

    public function update(Request $request, int $staff): RedirectResponse
    {
        $model = $this->find($staff);
        [$attributes, $services, $branches] = $this->validated($request, $model);

        me_change_log('Staff updated: '.$model->name, 'abs.staff.update')
            ->watch($model, ['services', 'branches'])
            ->run(fn () => DB::transaction(function () use ($model, $attributes, $services, $branches) {
                $model->update($attributes);
                $model->services()->sync($services);
                $model->branches()->sync($branches);
            }));

        return redirect()->route('abs.staff.index')->with('success', __('abs::abs.saved'));
    }

    public function destroy(int $staff): RedirectResponse
    {
        $model = $this->find($staff);
        // Soft delete: past appointments keep pointing at the staff record. The login link is released so the
        // user can be attached to a new staff profile (unique business_id + user_id).
        me_change_log('Staff deleted: '.$model->name, 'abs.staff.delete')->watch($model)->delete(function () use ($model) {
            $model->forceFill(['user_id' => null, 'accepts_bookings' => false])->save();
            $model->delete();
        });

        return back()->with('success', __('abs::abs.deleted'));
    }

    public function schedule(int $staff): View
    {
        $model = $this->find($staff);

        return view('abs::admin.staff.schedule', [
            'staff' => $model,
            'business' => $this->business(),
            'timeOff' => $model->timeOff()->where('ends_at', '>', CarbonImmutable::now()->subDays(30))->orderBy('starts_at')->get(),
            'canEdit' => $this->canInBusiness('abs_schedule.edit'),
        ]);
    }

    public function storeTimeOff(Request $request, int $staff): RedirectResponse
    {
        $model = $this->find($staff);
        $data = $request->validate([
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $timezone = $this->business()->timezone;
        [$startDate, $startTime] = explode('T', $data['starts_at']);
        [$endDate, $endTime] = explode('T', $data['ends_at']);
        $start = LocalTime::toUtc($startDate, $startTime, $timezone);
        $end = LocalTime::toUtc($endDate, $endTime, $timezone);

        if (! $start || ! $end) {
            throw ValidationException::withMessages(['starts_at' => 'That local time does not exist (daylight-saving change). Pick another time.']);
        }

        $timeOff = $model->timeOff()->create(['starts_at' => $start, 'ends_at' => $end, 'reason' => $data['reason'] ?? null]);
        me_change_log('Time off added for '.$model->name, 'abs.time-off.create')->record([], ['from' => $data['starts_at'], 'to' => $data['ends_at']]);

        return back()->with('success', __('abs::abs.saved'));
    }

    public function destroyTimeOff(int $staff, int $timeOff): RedirectResponse
    {
        $this->find($staff)->timeOff()->findOrFail($timeOff)->delete();

        return back()->with('success', __('abs::abs.deleted'));
    }

    private function form(Staff $staff): View
    {
        $business = $this->business();

        return view('abs::admin.staff.form', [
            'staff' => $staff,
            'business' => $business,
            'services' => $business->services()->orderBy('name')->get(['id', 'name', 'price_minor', 'duration_minutes']),
            'branches' => $business->branches()->orderBy('name')->get(['id', 'name']),
            'users' => User::query()
                ->whereIn('id', $business->members()->select('user_id'))
                ->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    private function find(int $id): Staff
    {
        return $this->business()->staff()->findOrFail($id);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, array<string, int|null>>, 2: array<int, int>}
     */
    private function validated(Request $request, ?Staff $staff = null): array
    {
        $business = $this->business();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'title' => ['nullable', 'string', 'max:150'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'user_id' => ['nullable', 'integer',
                Rule::exists('abs_business_members', 'user_id')->where('business_id', $business->getKey()),
                Rule::unique('abs_staff', 'user_id')->where('business_id', $business->getKey())->whereNull('deleted_at')->ignore($staff?->getKey()),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'services' => ['nullable', 'array'],
            'services.*.id' => ['required', 'integer', Rule::exists('abs_services', 'id')->where('business_id', $business->getKey())],
            'services.*.enabled' => ['nullable', 'boolean'],
            'services.*.price' => ['nullable', 'string', 'max:20'],
            'services.*.duration_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'branches' => ['nullable', 'array'],
            'branches.*' => ['integer', Rule::exists('abs_branches', 'id')->where('business_id', $business->getKey())],
        ]);

        $services = [];
        foreach ($data['services'] ?? [] as $index => $row) {
            if (empty($row['enabled'])) {
                continue;
            }
            try {
                $price = filled($row['price'] ?? null) ? Currency::toMinor($row['price'], $business->currency) : null;
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(["services.{$index}.price" => $e->getMessage()]);
            }
            $services[(int) $row['id']] = ['price_minor' => $price, 'duration_minutes' => $row['duration_minutes'] ?? null];
        }

        $attributes = collect($data)->only(['name', 'email', 'phone', 'title', 'bio', 'user_id'])->all() + [
            'is_active' => $request->boolean('is_active'),
            'accepts_bookings' => $request->boolean('accepts_bookings'),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];

        return [$attributes, $services, array_map('intval', $data['branches'] ?? [])];
    }
}
