<?php

declare(strict_types=1);

namespace App\Services\Email;

/**
 * The outcome of rendering a subject and a body against one recipient
 * (decision D-10): the resolved texts, still raw — the mail view escapes
 * them — and the tags the renderer did not recognise, so the UI can warn the
 * author about a typo instead of silently sending a blank.
 */
final readonly class RenderedEmail
{
    /**
     * @param  list<string>  $unknownTags
     */
    public function __construct(
        public string $subject,
        public string $body,
        public array $unknownTags = [],
    ) {}

    public function hasUnknownTags(): bool
    {
        return $this->unknownTags !== [];
    }
}
