<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Normalizer;

/**
 * Keeps email_normalized / phone_normalized in step with the raw columns
 * (decision A-11). The phone source is the first non-empty of the columns
 * named by phoneSourceColumns() — contacts prefer the mobile number.
 */
trait HasNormalizedContactColumns
{
    public static function bootHasNormalizedContactColumns(): void
    {
        static::saving(static function (self $model): void {
            $model->setAttribute('email_normalized', Normalizer::email($model->getAttribute('email')));

            $phone = null;

            foreach (static::phoneSourceColumns() as $column) {
                $candidate = $model->getAttribute($column);

                if (is_string($candidate) && trim($candidate) !== '') {
                    $phone = $candidate;

                    break;
                }
            }

            $model->setAttribute('phone_normalized', Normalizer::phone($phone));
        });
    }

    /**
     * @return list<string>
     */
    public static function phoneSourceColumns(): array
    {
        return ['phone'];
    }
}
