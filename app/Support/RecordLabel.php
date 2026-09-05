<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A human-readable label for any record, for notifications, audit rows and
 * merge dialogs. Tries the conventional attributes in order and never touches
 * an attribute the model does not define (strict models throw on those).
 */
final class RecordLabel
{
    /**
     * @var list<string>
     */
    private const CANDIDATES = ['display_name', 'full_name', 'title', 'name', 'key'];

    public static function of(Model $record): string
    {
        foreach (self::CANDIDATES as $attribute) {
            if (! self::defines($record, $attribute)) {
                continue;
            }

            $value = $record->getAttribute($attribute);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return (string) $record->getKey();
    }

    private static function defines(Model $record, string $attribute): bool
    {
        return array_key_exists($attribute, $record->getAttributes())
            || $record->hasGetMutator($attribute)
            || $record->hasAttributeMutator($attribute);
    }
}
