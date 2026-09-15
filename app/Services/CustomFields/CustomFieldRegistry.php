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
 * Building a form, a table, a filter set, an action, an importer row or an
 * exporter needs the active definitions of one entity in sort order — many
 * times per request. The registry reads them once per instance and answers
 * from memory afterwards.
 *
 * The container binds it scoped (AppServiceProvider::register): one instance
 * per HTTP request, per queued job and per console command, so every
 * `app(CustomFieldRegistry::class)` of the same request shares the memo and a
 * list page or an import pays one query per entity. It is never a
 * process-wide cache: scoped instances are dropped between requests and jobs,
 * and AppServiceProvider forgets the instance whenever a definition is saved
 * or deleted, so a definition an administrator changes — or a test creates —
 * is read afresh by the next consumer. Callers therefore resolve it from the
 * container when they need it rather than holding on to an instance.
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
