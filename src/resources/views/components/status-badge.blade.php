@props(['status'])
<span {{ $attributes->merge(['class' => 'badge bg-'.$status->color().'-subtle text-'.$status->color()]) }}>{{ method_exists($status, 'label') ? $status->label() : ucwords(str_replace('_', ' ', $status->value)) }}</span>
