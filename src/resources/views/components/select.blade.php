@props(['name', 'label', 'options' => [], 'value' => null, 'required' => false, 'placeholder' => null, 'help' => null, 'icon' => null])
<div class="form-group mb-3">
    <label for="{{ $name }}" class="font-weight-bold text-primary">
        @if($icon)<i class="{{ $icon }} me-1"></i>@endif {{ $label }} @if($required)<span class="text-danger">*</span>@endif
    </label>
    <select name="{{ $name }}" id="{{ $name }}" {{ $attributes->merge(['class' => 'form-select form-select-sm'.($errors->has($name) ? ' is-invalid' : '')]) }} @required($required)>
        @if($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected((string) old($name, $value) === (string) $optionValue)>{{ $optionLabel }}</option>
        @endforeach
    </select>
    @if($help)<small class="form-text text-muted">{{ $help }}</small>@endif
    @error($name)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</div>
