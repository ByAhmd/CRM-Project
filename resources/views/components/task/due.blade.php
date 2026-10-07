{{-- The relative due date (D-27) on the organisation calendar; the placeholder when the task has no due date. --}}
@props(['task'])
{{ \App\Filament\Support\TaskVisuals::relativeDue($task) ?? __('tasks.due.none') }}
