@extends('me::master')

@section('title', 'Booking Diagnostics')

@section('content')
<div class="card glass-card w-100">
    <p class="small text-muted">Live checks of this installation. Secret values are never shown.</p>
    <div class="table-responsive">
        <table class="table table-sm table-bordered table-encodex">
            <thead class="text-center"><tr><th>Check</th><th>Result</th><th>Details</th></tr></thead>
            <tbody>
            @foreach($checks as $check)
                <tr>
                    <td>{{ $check['label'] }}</td>
                    <td class="text-center">
                        @if($check['ok'])<span class="badge bg-success-subtle text-success">OK</span>
                        @elseif($check['critical'])<span class="badge bg-danger-subtle text-danger">FAIL</span>
                        @else<span class="badge bg-warning-subtle text-warning">WARN</span>@endif
                    </td>
                    <td class="small">{{ $check['detail'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
