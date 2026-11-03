<?php

namespace ME\Abs\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use ME\Abs\Http\Controllers\Controller;
use ME\Abs\Models\ServiceCategory;

class CategoryController extends Controller
{
    public function index(): View
    {
        return view('abs::admin.categories.index', [
            'categories' => $this->business()->serviceCategories()->withCount('services')->orderBy('sort_order')->orderBy('name')->paginate($this->pageSize()),
        ]);
    }

    public function create(): View
    {
        return view('abs::admin.categories.form', ['category' => new ServiceCategory(['is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $category = $this->business()->serviceCategories()->create($this->validated($request));
        me_change_log('Service category created: '.$category->name, 'abs.category.create')->record([], $category->only(['name']));

        return redirect()->route('abs.categories.index')->with('success', __('abs::abs.saved'));
    }

    public function edit(int $category): View
    {
        return view('abs::admin.categories.form', ['category' => $this->find($category)]);
    }

    public function update(Request $request, int $category): RedirectResponse
    {
        $model = $this->find($category);
        $data = $this->validated($request);
        me_change_log('Service category updated: '.$model->name, 'abs.category.update')->watch($model)->run(fn () => $model->update($data));

        return redirect()->route('abs.categories.index')->with('success', __('abs::abs.saved'));
    }

    public function destroy(int $category): RedirectResponse
    {
        $model = $this->find($category);
        // Services keep existing; their category_id becomes null.
        me_change_log('Service category deleted: '.$model->name, 'abs.category.delete')->watch($model)->delete(fn () => $model->delete());

        return back()->with('success', __('abs::abs.deleted'));
    }

    private function find(int $id): ServiceCategory
    {
        return $this->business()->serviceCategories()->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        return ['is_active' => $request->boolean('is_active'), 'sort_order' => (int) ($data['sort_order'] ?? 0)] + $data;
    }
}
