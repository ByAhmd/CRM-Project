<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\WorkspacePanel;

/**
 * One card of the workspace screen (decision D-23): the workspace and, when
 * the viewer may enter it, the address of its home. A card without a URL is
 * shown locked and links nowhere.
 */
final readonly class WorkspaceCard
{
    public function __construct(
        public WorkspacePanel $workspace,
        public ?string $url,
    ) {}

    public function isEnterable(): bool
    {
        return $this->url !== null;
    }
}
