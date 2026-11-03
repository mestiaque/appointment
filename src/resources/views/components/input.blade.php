@props(['name', 'label', 'type' => 'text', 'value' => null, 'required' => false, 'help' => null, 'icon' => null])
@php $errorKey = str_replace(['[', ']'], ['.', ''], $name); @endphp
<div class="form-group mb-3">
    <label for="{{ $attributes->get('id', $errorKey) }}" class="font-weight-bold text-primary">
        @if($icon)<i class="{{ $icon }} me-1"></i>@endif {{ $label }} @if($required)<span class="text-danger">*</span>@endif
    </label>
    @if($type === 'textarea')
        <textarea name="{{ $name }}" id="{{ $attributes->get('id', $errorKey) }}" {{ $attributes->except('id')->merge(['class' => 'form-control form-control-sm'.($errors->has($errorKey) ? ' is-invalid' : ''), 'rows' => 3]) }} @required($required)>{{ old($errorKey, $value) }}</textarea>
    @else
        <input type="{{ $type }}" name="{{ $name }}" id="{{ $attributes->get('id', $errorKey) }}" value="{{ old($errorKey, $value) }}" {{ $attributes->except('id')->merge(['class' => 'form-control form-control-sm'.($errors->has($errorKey) ? ' is-invalid' : '')]) }} @required($required)>
    @endif
    @if($help)<small class="form-text text-muted">{{ $help }}</small>@endif
    @error($errorKey)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</div>
