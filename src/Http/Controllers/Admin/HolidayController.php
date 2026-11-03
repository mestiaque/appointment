<?php

namespace ME\Abs\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use ME\Abs\Http\Controllers\Controller;

class HolidayController extends Controller
{
    public function index(): View
    {
        return view('abs::admin.holidays.index', [
            'holidays' => $this->business()->holidays()->with('branch:id,name')->orderByDesc('date')->paginate($this->pageSize()),
            'branches' => $this->business()->branches()->orderBy('name')->get(['id', 'name']),
            'canEdit' => $this->canInBusiness('abs_schedule.edit'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:150'],
            'branch_id' => ['nullable', 'integer', Rule::exists('abs_branches', 'id')->where('business_id', $this->business()->getKey())],
        ]);

        $holiday = $this->business()->holidays()->create($data);
        me_change_log('Holiday added: '.$holiday->name, 'abs.holiday.create')->record([], $data);

        return back()->with('success', __('abs::abs.saved'));
    }

    public function destroy(int $holiday): RedirectResponse
    {
        $model = $this->business()->holidays()->findOrFail($holiday);
        me_change_log('Holiday removed: '.$model->name, 'abs.holiday.delete')->watch($model)->delete(fn () => $model->delete());

        return back()->with('success', __('abs::abs.deleted'));
    }
}
