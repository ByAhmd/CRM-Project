{{-- A person's initials avatar (A-23, D-27), named for screen readers; null reads as unassigned. --}}
@props(['name' => null])
{{ \App\Filament\Support\TaskVisuals::avatar($name) }}
