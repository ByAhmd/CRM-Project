<?php

declare(strict_types=1);

namespace App\Filament\Support;

use BackedEnum;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One screen offered on a workspace home page (decision D-23): already
 * checked against the viewer's access, so the home never shows a link that
 * answers 403 (ISS-062 in the reference project).
 */
final readonly class WorkspaceLink
{
    public function __construct(
        public string $label,
        public string $url,
        public string|BackedEnum|Htmlable|null $icon = null,
        public ?string $description = null,
    ) {}
}
