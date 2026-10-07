<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\DueTone;
use Carbon\CarbonImmutable;

/**
 * One task's due date as the Tasks workspace reads it (decision D-27): the
 * relative words («اليوم 17:00», «غداً», «الخميس», «متأخرة 3 أيام»), the tone
 * that colours them, and the exact moment on the organisation clock for the
 * tooltip and the `<time datetime>` attribute. Built by RelativeDueDates.
 */
final readonly class RelativeDue
{
    public function __construct(
        public string $text,
        public DueTone $tone,
        public CarbonImmutable $moment,
    ) {}

    /** "2026-10-07 17:00" on the organisation clock — the format every task screen prints. */
    public function absolute(): string
    {
        return $this->moment->format('Y-m-d H:i');
    }

    /** The machine-readable moment for `<time datetime>`, with its offset. */
    public function iso(): string
    {
        return $this->moment->toIso8601String();
    }
}
