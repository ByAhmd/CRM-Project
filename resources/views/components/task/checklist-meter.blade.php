{{-- The checklist meter (D-25, D-27): done/total and a bar; renders nothing for a task without a checklist. --}}
@props(['task'])
{{ \App\Filament\Support\TaskVisuals::checklistMeterFor($task) }}
