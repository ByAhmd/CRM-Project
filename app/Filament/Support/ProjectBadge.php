<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Concerns\BelongsToProject;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\CurrentProject;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component as LivewireComponent;

/**
 * The project a Sales record belongs to, shown as a read-only badge
 * (decision D-30) — a record is never offered a project to choose.
 *
 * One rule answers "which project is this form or page about", for the
 * badge and for every picker that must offer records of the same project:
 * the record's own project when there is a record (edit and view pages, the
 * edit modal of a relation manager); the owner record's project when the
 * form sits in a relation manager without a record yet (a contact or a deal
 * created from an account's page lands in the account's project, whatever
 * project the user is working in); otherwise the project the signed-in user
 * is working in (the create pages), or the default project when nobody is
 * signed in. The relation is loaded explicitly, so a page that did not eager
 * load it never trips the lazy-loading guard.
 */
final class ProjectBadge
{
    public static function entry(string $label): TextEntry
    {
        return TextEntry::make('project_badge')
            ->label($label)
            ->badge()
            ->state(fn (?Model $record, ?LivewireComponent $livewire): string => self::projectFor($record, $livewire)->display_name)
            ->color(fn (?Model $record, ?LivewireComponent $livewire): string => self::projectFor($record, $livewire)->color->value);
    }

    public static function projectFor(?Model $record, ?LivewireComponent $livewire = null): Project
    {
        $project = self::projectOf($record)
            ?? self::projectOf($livewire instanceof RelationManager ? $livewire->getOwnerRecord() : null);

        if ($project instanceof Project) {
            return $project;
        }

        $user = auth()->user();
        $current = app(CurrentProject::class);

        return $user instanceof User ? $current->for($user) : $current->default();
    }

    public static function projectIdFor(?Model $record, ?LivewireComponent $livewire = null): int
    {
        return (int) self::projectFor($record, $livewire)->getKey();
    }

    /** The project of a project-partitioned record that already carries one; null for anything else. */
    private static function projectOf(?Model $record): ?Project
    {
        if (! $record instanceof Model
            || ! in_array(BelongsToProject::class, class_uses_recursive($record), true)
            || $record->getAttribute('project_id') === null) {
            return null;
        }

        $record->loadMissing('project');
        $project = $record->getRelationValue('project');

        return $project instanceof Project ? $project : null;
    }
}
