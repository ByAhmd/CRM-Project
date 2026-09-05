<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Derived from the stage kind by DealStageWorkflow (decision D-8); never set by a form.
 */
enum DealStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    public function getLabel(): string
    {
        return __('enums.deal_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'primary',
            self::Won => 'success',
            self::Lost => 'danger',
        };
    }

    public static function fromStageKind(StageKind $kind): self
    {
        return match ($kind) {
            StageKind::Open => self::Open,
            StageKind::Won => self::Won,
            StageKind::Lost => self::Lost,
        };
    }

    public function isClosed(): bool
    {
        return $this !== self::Open;
    }
}
