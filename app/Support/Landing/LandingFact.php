<?php

declare(strict_types=1);

namespace App\Support\Landing;

/**
 * One figure of the landing page's facts strip (decision D-24): a number
 * read from the code and the words around it, the noun counted in the
 * reader's language (Arabic counts take six forms).
 */
final readonly class LandingFact
{
    public function __construct(
        public string $key,
        public int $value,
    ) {}

    public function label(): string
    {
        return trans_choice('landing.facts.'.$this->key.'.label', $this->value);
    }

    public function note(): string
    {
        return __('landing.facts.'.$this->key.'.note');
    }
}
