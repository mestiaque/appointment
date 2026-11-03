@extends('me::master')

@section('title', 'Team Members')

@push('buttons')
    @include('abs::admin.partials.business-bar')
@endpush

@section('content')
<div class="row">
    <div class="col-lg-8">
        <div class="card glass-card w-100">
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover table-striped table-encodex">
                    <thead class="text-center"><tr><th>Name</th><th>E-mail</th><th>Business role</th><th>Active</th><th>Actions</th></tr></thead>
                    <tbody>
                    @forelse($members as $member)
                        <tr>
                            <td>{{ $member->user?->name }}</td>
                            <td>{{ $member->user?->email }}</td>
                            <td colspan="2">
                                <form method="POST" action="{{ route('abs.members.update', $member->id) }}" class="d-flex gap-2 align-items-center">
                                    @csrf @method('PUT')
                                    <select name="role" class="form-select form-select-sm w-auto">
                                        @foreach($roles as $role)<option value="{{ $role->value }}" @selected($member->role === $role)>{{ $role->label() }}</option>@endforeach
                                    </select>
                                    <div class="form-check form-switch mb-0">
                                        <input type="hidden" name="is_active" value="0">
                                        <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($member->is_active)>
                                    </div>
                                    <button class="btn btn-sm btn-encodex-edit" title="Save"><i class="fas fa-save"></i></button>
                                </form>
                            </td>
                            <td class="text-center"><x-abs::delete-button :action="route('abs.members.destroy', $member->id)" confirm="Remove this member from the business?" /></td>
                        </tr>
                    @empty
                        <x-abs::empty-row colspan="5" />
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($members->hasPages())<div class="mt-3">{{ $members->links('pagination::bootstrap-5') }}</div>@endif
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow mb-4">
            <div class="card-body">
                <h6 class="fw-bold mb-3"><i class="fas fa-user-plus me-1"></i> Add member</h6>
                <form method="POST" action="{{ route('abs.members.store') }}">
                    @csrf
                    <x-abs::input name="email" type="email" label="User e-mail" required help="The user must exist (Users & Roles → Users) and have a booking role." />
                    <x-abs::select name="role" label="Business role" :options="collect($roles)->mapWithKeys(fn ($r) => [$r->value => $r->label()])->all()" value="staff" required />
                    <button class="btn btn-sm btn-savex w-100"><i class="fas fa-plus me-1"></i> Add</button>
                </form>
                <hr>
                <p class="small text-muted mb-0">
                    <b>Owner</b>: everything. <b>Manager</b>: everything except deleting the business.
                    <b>Receptionist</b>: appointments and recording payments. <b>Staff</b>: only their own appointments and status updates.
                    The user's metheme role must also include the permission.
                </p>
            </div>
        </div>
    </div>
</div>
@endsection
