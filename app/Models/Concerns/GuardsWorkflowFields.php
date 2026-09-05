<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use LogicException;

/**
 * Columns only a workflow service may change (statuses, stages, stamps).
 *
 * A form, a bulk action or a stray tinker session cannot rewrite them: any
 * update touching a guarded attribute throws unless it runs inside
 * withoutWorkflowGuard(), which the workflow services use around their own
 * writes. The lift is scoped to the callback, never process-global.
 */
trait GuardsWorkflowFields
{
    private static bool $workflowGuardLifted = false;

    /**
     * @return list<string>
     */
    abstract public static function workflowGuardedAttributes(): array;

    public static function bootGuardsWorkflowFields(): void
    {
        static::updating(static function (self $model): void {
            if (self::$workflowGuardLifted) {
                return;
            }

            $touched = array_values(array_intersect(array_keys($model->getDirty()), static::workflowGuardedAttributes()));

            if ($touched !== []) {
                throw new LogicException(sprintf(
                    '%s attributes [%s] can only be changed through their workflow service.',
                    static::class,
                    implode(', ', $touched),
                ));
            }
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutWorkflowGuard(callable $callback): mixed
    {
        $previous = self::$workflowGuardLifted;
        self::$workflowGuardLifted = true;

        try {
            return $callback();
        } finally {
            self::$workflowGuardLifted = $previous;
        }
    }
}
