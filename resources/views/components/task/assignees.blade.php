{{-- Who holds the task (D-28): overlapping avatars, the first assignee's name and «+N» for the others; nothing for a task nobody holds. --}}
@props(['task'])
{{ \App\Filament\Support\TaskVisuals::assignees($task) }}
