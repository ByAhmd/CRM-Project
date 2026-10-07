{{-- The task priority flag (D-27): urgent red, high orange, medium and low muted; named for screen readers. --}}
@props(['priority', 'withLabel' => false])
{{ \App\Filament\Support\TaskVisuals::priorityFlag($priority, (bool) $withLabel) }}
