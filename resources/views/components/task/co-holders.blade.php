{{-- The colleagues sharing the task beside the given holder (D-28): their avatars, named «مشتركة مع …»; nothing when nobody else holds it. --}}
@props(['task', 'holder' => null])
{{ \App\Filament\Support\TaskVisuals::coHolders($task, $holder) }}
