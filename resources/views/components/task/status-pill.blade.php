{{-- The task status pill (D-27): Filament's badge in the TaskStatus colour, with the status icon. --}}
@props(['status'])
{{ \App\Filament\Support\TaskVisuals::statusPill($status) }}
