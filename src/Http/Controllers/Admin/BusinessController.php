<?php

namespace ME\Abs\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use ME\Abs\Enums\BusinessRole;
use ME\Abs\Http\Controllers\Controller;
use ME\Abs\Models\Business;
use ME\Abs\Support\BusinessAccess;
use ME\Abs\Support\Currency;
use ME\Abs\Support\CurrentBusiness;

class BusinessController extends Controller
{
    public function __construct(private BusinessAccess $access, private CurrentBusiness $current) {}

    public function index(): View
    {
        $businesses = $this->access->accessibleBusinesses(auth()->user())
            ->withCount(['staff', 'services', 'appointments'])
            ->paginate($this->pageSize());

        return view('abs::admin.businesses.index', [
            'businesses' => $businesses,
            'currentId' => $this->current->get()?->getKey(),
        ]);
    }

    public function create(): View
    {
        return view('abs::admin.businesses.form', ['business' => new Business([
            'timezone' => config('abs.default_timezone'),
            'currency' => config('abs.default_currency'),
            'status' => 'active',
        ])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $business = DB::transaction(function () use ($data, $request) {
            $business = Business::query()->create($data + [
                'slug' => $this->uniqueSlug($data['name']),
                'owner_user_id' => $request->user()->getKey(),
            ]);
            $business->members()->create(['user_id' => $request->user()->getKey(), 'role' => BusinessRole::Owner, 'is_active' => true]);
            $business->bookingSetting()->create([]);

            return $business;
        });

        $business->syncMediaFromRequest($request, 'logo', 'logo');
        $this->current->switchTo($business);
        me_change_log('Booking business created: '.$business->name, 'abs.business.create')->record([], $business->only(['name', 'timezone', 'currency']));

        return redirect()->route('abs.settings.edit')->with('success', __('abs::abs.saved'));
    }

    public function edit(): View
    {
        return view('abs::admin.businesses.form', ['business' => $this->business()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $business = $this->business();
        $data = $this->validated($request, $business);

        me_change_log('Booking business updated: '.$business->name, 'abs.business.update')
            ->watch($business)
            ->run(fn () => $business->update($data));
        $business->syncMediaFromRequest($request, 'logo', 'logo');

        return back()->with('success', __('abs::abs.saved'));
    }

    public function destroy(): RedirectResponse
    {
        $business = $this->business();
        me_change_log('Booking business deleted: '.$business->name, 'abs.business.delete')->watch($business)->delete(fn () => $business->delete());
        $this->current->forget();

        return redirect()->route('abs.businesses.index')->with('success', __('abs::abs.deleted'));
    }

    public function switch(int $business): RedirectResponse
    {
        $target = Business::query()->findOrFail($business);
        abort_unless($this->access->canAccess(auth()->user(), $target), 403);

        $this->current->switchTo($target);

        return redirect()->route('abs.dashboard')->with('success', __('abs::abs.business_switched'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Business $business = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', 'string', 'size:3', 'alpha', 'uppercase'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;

        for ($i = 2; Business::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /**
     * @return array<int, string>
     */
    public static function currencies(): array
    {
        return Currency::commonCodes();
    }
}
