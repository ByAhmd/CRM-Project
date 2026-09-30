<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * What an entry of a task's thread is (decision D-17): a progress entry —
 * a start, a progress update, a completion or a reopening (D-14 amendment,
 * 2026-09-28) — or a comment from anyone who may view the task.
 */
enum TaskUpdateKind: string implements HasColor, HasIcon, HasLabel
{
    case Progress = 'progress';
    case Comment = 'comment';

    public function getLabel(): string
    {
        return __('enums.task_update_kind.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Progress => 'info',
            self::Comment => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Progress => Heroicon::OutlinedFlag,
            self::Comment => Heroicon::OutlinedChatBubbleLeftRight,
        };
    }
}
