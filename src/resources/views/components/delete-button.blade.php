@props(['action', 'confirm' => 'Are you sure you want to delete this?'])
<form action="{{ $action }}" method="POST" class="d-inline">
    @csrf
    @method('DELETE')
    <button type="submit" class="btn btn-sm btn-encodex-delete" title="Delete" onclick="return confirm(@js($confirm))">
        <i class="fas fa-trash"></i>
    </button>
</form>
