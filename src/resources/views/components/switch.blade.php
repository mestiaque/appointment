@props(['name', 'label', 'checked' => false, 'help' => null])
<div class="form-group mb-3">
    <div class="form-check form-switch">
        <input type="hidden" name="{{ $name }}" value="0">
        <input type="checkbox" class="form-check-input" id="{{ $name }}" name="{{ $name }}" value="1" @checked(old($name, $checked))>
        <label class="form-check-label font-weight-bold" for="{{ $name }}">{{ $label }}</label>
        @if($help)<small class="form-text text-muted d-block">{{ $help }}</small>@endif
    </div>
</div>
