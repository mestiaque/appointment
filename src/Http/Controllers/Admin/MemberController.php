<?php

namespace ME\Abs\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use ME\Abs\Enums\BusinessRole;
use ME\Abs\Http\Controllers\Controller;
use ME\Abs\Models\BusinessMember;
use ME\Models\User;

/**
 * Adds existing users (created on the metheme Users page) to the current business.
 */
class MemberController extends Controller
{
    public function index(): View
    {
        return view('abs::admin.members.index', [
            'members' => $this->business()->members()->with('user')->orderBy('role')->paginate($this->pageSize()),
            'roles' => BusinessRole::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', Rule::exists('users', 'email')],
            'role' => ['required', Rule::enum(BusinessRole::class)],
        ], ['email.exists' => 'No user with this e-mail. Create the user on the Users page first.']);

        $this->guardOwnerRole($data['role']);
        $user = User::query()->where('email', $data['email'])->firstOrFail();

        $this->business()->members()->updateOrCreate(['user_id' => $user->getKey()], ['role' => $data['role'], 'is_active' => true]);
        me_change_log('Booking member added: '.$user->email, 'abs.member.create')->record([], ['user' => $user->email, 'role' => $data['role']]);

        return back()->with('success', __('abs::abs.saved'));
    }

    public function update(Request $request, int $member): RedirectResponse
    {
        $membership = $this->membership($member);
        $data = $request->validate([
            'role' => ['required', Rule::enum(BusinessRole::class)],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $this->guardOwnerRole($data['role']);
        $this->guardLastOwner($membership, $data['role'], (bool) ($data['is_active'] ?? false));

        me_change_log('Booking member updated: '.$membership->user?->email, 'abs.member.update')
            ->watch($membership)
            ->run(fn () => $membership->update(['role' => $data['role'], 'is_active' => (bool) ($data['is_active'] ?? false)]));

        return back()->with('success', __('abs::abs.saved'));
    }

    public function destroy(int $member): RedirectResponse
    {
        $membership = $this->membership($member);
        $this->guardLastOwner($membership, null, false);

        me_change_log('Booking member removed: '.$membership->user?->email, 'abs.member.delete')->watch($membership)->delete(fn () => $membership->delete());

        return back()->with('success', __('abs::abs.deleted'));
    }

    private function membership(int $id): BusinessMember
    {
        return $this->business()->members()->with('user')->findOrFail($id);
    }

    /**
     * Only owners (or privileged users) may hand out the owner role.
     */
    private function guardOwnerRole(string $role): void
    {
        if ($role === BusinessRole::Owner->value) {
            $this->authorizeInBusiness('abs_business.delete');
        }
    }

    private function guardLastOwner(BusinessMember $membership, ?string $newRole, bool $active): void
    {
        $stillOwner = $newRole === BusinessRole::Owner->value && $active;
        if ($membership->role !== BusinessRole::Owner || $stillOwner) {
            return;
        }

        $owners = $this->business()->members()->where('role', BusinessRole::Owner)->where('is_active', true)->count();
        abort_if($owners <= 1, 422, 'A business needs at least one active owner.');
    }
}
