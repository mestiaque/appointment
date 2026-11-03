<div class="card shadow mb-4"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h6 class="fw-bold mb-0"><i class="fas fa-business-time me-1"></i> Weekly working hours</h6>
        @if($canEdit)<button type="button" wire:click="copyMondayToWeekdays" class="btn btn-sm btn-outline-secondary">Copy Monday to Tue–Fri</button>@endif
    </div>
    <p class="small text-muted">Local business time (24-hour, HH:MM). Add several intervals for split shifts; use 24:00 as the end to continue past midnight into the next day's 00:00 interval.</p>
    @if($errors->any())<div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>@endif

    @foreach($days as $day => $label)
        <div class="row g-2 py-2 border-bottom align-items-start" wire:key="day-{{ $day }}">
            <div class="col-12 col-md-2 fw-bold pt-md-1">{{ $label }}</div>
            @foreach(['work' => ['primary', 'Working hours', 'interval'], 'breaks' => ['warning', 'Breaks', 'break']] as $type => [$color, $heading, $noun])
                <div class="col-12 col-sm-6 {{ $type === 'work' ? 'col-md-6' : 'col-md-4' }}">
                    <div class="small text-muted d-md-none">{{ $heading }}</div>
                    @foreach(($this->{$type}[$day] ?? []) as $i => $row)
                        <div class="d-flex gap-1 mb-1 align-items-center" wire:key="{{ $type }}-{{ $day }}-{{ $i }}">
                            <input type="text" inputmode="numeric" placeholder="09:00" maxlength="5" aria-label="{{ $label }} {{ $noun }} start"
                                   wire:model="{{ $type }}.{{ $day }}.{{ $i }}.start" class="form-control form-control-sm text-center" style="width:5.2rem" @disabled(! $canEdit)>
                            <span>–</span>
                            <input type="text" inputmode="numeric" placeholder="17:00" maxlength="5" aria-label="{{ $label }} {{ $noun }} end"
                                   wire:model="{{ $type }}.{{ $day }}.{{ $i }}.end" class="form-control form-control-sm text-center" style="width:5.2rem" @disabled(! $canEdit)>
                            @if($canEdit)<button type="button" wire:click="removeRow('{{ $type }}', {{ $day }}, {{ $i }})" class="btn btn-sm btn-link text-danger px-1" title="Remove"><i class="fas fa-times"></i></button>@endif
                        </div>
                    @endforeach
                    @if($canEdit)
                        <button type="button" wire:click="addRow('{{ $type }}', {{ $day }})" class="btn btn-sm btn-outline-{{ $color }} py-0"><i class="fas fa-plus"></i> {{ $noun }}</button>
                    @elseif(empty($this->{$type}[$day]))
                        <span class="text-muted small">{{ $type === 'work' ? 'Off' : '—' }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach

    @if($canEdit)
        <div class="text-end mt-3">
            <button wire:click="save" wire:loading.attr="disabled" class="btn btn-savex"><i class="fas fa-save me-1"></i> Save hours</button>
        </div>
    @endif
</div></div>
