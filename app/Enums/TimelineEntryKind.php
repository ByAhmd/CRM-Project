<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * What a timeline entry is about (module row 12).
 *
 * The kind decides the icon and the colour of the entry on the record's
 * feed. Labels live in lang/{locale}/timeline.php so the enums file stays the
 * home of the business enums.
 */
enum TimelineEntryKind: string implements HasColor, HasIcon, HasLabel
{
    case Activity = 'activity';
    case Note = 'note';
    case Task = 'task';
    case StatusChange = 'status_change';
    case StageChange = 'stage_change';
    case Attachment = 'attachment';
    case Assignment = 'assignment';
    case Conversion = 'conversion';
    case Lifecycle = 'lifecycle';
    case Audit = 'audit';

    public function getLabel(): string
    {
        return __('timeline.kinds.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Activity => 'info',
            self::Note => 'gray',
            self::Task => 'success',
            self::StatusChange => 'warning',
            self::StageChange => 'primary',
            self::Attachment => 'gray',
            self::Assignment => 'warning',
            self::Conversion => 'success',
            self::Lifecycle => 'primary',
            self::Audit => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Activity => Heroicon::OutlinedBolt,
            self::Note => Heroicon::OutlinedDocumentText,
            self::Task => Heroicon::OutlinedCheckCircle,
            self::StatusChange => Heroicon::OutlinedArrowPath,
            self::StageChange => Heroicon::OutlinedArrowTrendingUp,
            self::Attachment => Heroicon::OutlinedPaperClip,
            self::Assignment => Heroicon::OutlinedUserCircle,
            self::Conversion => Heroicon::OutlinedSparkles,
            self::Lifecycle => Heroicon::OutlinedFlag,
            self::Audit => Heroicon::OutlinedClipboardDocumentList,
        };
    }
}
