<?php

declare(strict_types=1);

namespace App\Services\CustomFields;

use App\Enums\CustomFieldEntity;
use App\Models\Account;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The lookup every consumer of the engine goes through (decision D-9).
 *
 * Building a form, a table, a filter set, an importer or an exporter needs the
 * active definitions of one entity in sort order — several times per request.
 * The registry reads them once per instance and answers from memory
 * afterwards, so a page that resolves it once pays one query for all of its
 * schemas.
 *
 * Memoisation is per instance on purpose: the container is not asked to treat
 * this as a singleton (the panel's provider is not the place for it and a
 * process-wide cache would go stale the moment an administrator saves a
 * definition). A caller that wants the saving resolves it once and passes the
 * instance around; a caller that resolves it per call simply pays a query per
 * call, and never reads a stale definition.
 *
 * The entity ↔ model mapping lives here rather than on CustomFieldEntity: the
 * enum is a shared, frozen contract, and only this module needs to translate
 * a case into an Eloquent class.
 */
final class CustomFieldRegistry
{
    /** @var array<string, Collection<int, CustomField>> */
    private array $memo = [];

    /**
     * The active definitions of one entity, in the order they are presented.
     *
     * @return Collection<int, CustomField>
     */
    public function active(CustomFieldEntity $entity): Collection
    {
        return $this->memo[$entity->value] ??= CustomField::query()
            ->forEntity($entity)
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * The active definitions keyed by their machine key.
     *
     * @return array<string, CustomField>
     */
    public function keyed(CustomFieldEntity $entity): array
    {
        $keyed = [];

        foreach ($this->active($entity) as $field) {
            $keyed[(string) $field->getAttribute('key')] = $field;
        }

        return $keyed;
    }

    /**
     * @return class-string<Model>
     */
    public static function modelClass(CustomFieldEntity $entity): string
    {
        return match ($entity) {
            CustomFieldEntity::Lead => Lead::class,
            CustomFieldEntity::Contact => Contact::class,
            CustomFieldEntity::Account => Account::class,
            CustomFieldEntity::Deal => Deal::class,
        };
    }

    /** The entity a record stands for, null when the model carries no custom fields. */
    public static function entityFor(Model $record): ?CustomFieldEntity
    {
        foreach (CustomFieldEntity::cases() as $case) {
            $class = self::modelClass($case);

            if ($record instanceof $class) {
                return $case;
            }
        }

        return null;
    }
}
