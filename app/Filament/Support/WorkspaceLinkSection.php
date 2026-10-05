<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * A titled group of screens on a workspace home page (decision D-23).
 */
final readonly class WorkspaceLinkSection
{
    /**
     * @param  list<WorkspaceLink>  $links
     */
    public function __construct(
        public ?string $heading,
        public array $links,
    ) {}
}
