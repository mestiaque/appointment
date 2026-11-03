<?php

namespace ME\Abs\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use ME\Abs\Http\Controllers\Controller;
use ME\Abs\Models\Branch;

class BranchController extends Controller
{
    public function index(): View
    {
        return view('abs::admin.branches.index', [
            'branches' => $this->business()->branches()->withCount('staff')->orderBy('sort_order')->orderBy('name')->paginate($this->pageSize()),
        ]);
    }

    public function create(): View
    {
        return view('abs::admin.branches.form', ['branch' => new Branch(['is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $branch = $this->business()->branches()->create($this->validated($request));
        me_change_log('Branch created: '.$branch->name, 'abs.branch.create')->record([], $branch->only(['name', 'address']));

        return redirect()->route('abs.branches.index')->with('success', __('abs::abs.saved'));
    }

    public function edit(int $branch): View
    {
        return view('abs::admin.branches.form', ['branch' => $this->find($branch)]);
    }

    public function update(Request $request, int $branch): RedirectResponse
    {
        $model = $this->find($branch);
        $data = $this->validated($request);
        me_change_log('Branch updated: '.$model->name, 'abs.branch.update')->watch($model)->run(fn () => $model->update($data));

        return redirect()->route('abs.branches.index')->with('success', __('abs::abs.saved'));
    }

    public function destroy(int $branch): RedirectResponse
    {
        $model = $this->find($branch);
        // Appointments keep their history: branch_id is set to null by the foreign key.
        me_change_log('Branch deleted: '.$model->name, 'abs.branch.delete')->watch($model)->delete(fn () => $model->delete());

        return back()->with('success', __('abs::abs.deleted'));
    }

    private function find(int $id): Branch
    {
        return $this->business()->branches()->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return ['is_active' => $request->boolean('is_active'), 'sort_order' => (int) ($data['sort_order'] ?? 0)] + $data;
    }
}
