<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomFieldEntity;
use App\Models\Concerns\AuditsAsLookup;
use App\Models\Concerns\HasLocalisedName;
use Database\Factories\EmailTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A bilingual email template (decision D-10).
 *
 * Subject and body exist in both languages; the body is plain text with
 * merge tags that EmailTemplateRenderer resolves at send time. `entity`
 * narrows the template to leads or contacts — null offers it for both. The
 * template is settings data (audited as a lookup) but sent activities point
 * back at it by id, so it is soft-deleted rather than removed.
 *
 * @property ?CustomFieldEntity $entity
 * @property bool $is_active
 * @property-read string $display_name
 */
#[Fillable(['name_ar', 'name_en', 'subject_ar', 'subject_en', 'body_ar', 'body_en', 'entity', 'is_active', 'sort'])]
final class EmailTemplate extends Model
{
    use AuditsAsLookup;

    /** @use HasFactory<EmailTemplateFactory> */
    use HasFactory;

    use HasLocalisedName;
    use SoftDeletes;

    /** @var list<string> */
    protected static array $recordEvents = ['created', 'updated', 'deleted', 'restored'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity' => CustomFieldEntity::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public static function auditedAttributes(): array
    {
        return ['name_ar', 'name_en', 'subject_ar', 'subject_en', 'entity', 'is_active'];
    }

    /** The subject in the given locale, falling back to the other language so it is never blank. */
    public function subjectFor(string $locale): string
    {
        return $this->localised('subject', $locale);
    }

    /** The body in the given locale, falling back to the other language so it is never blank. */
    public function bodyFor(string $locale): string
    {
        return $this->localised('body', $locale);
    }

    /** Whether the template may be offered for the given entity (a template without an entity fits every one). */
    public function appliesTo(CustomFieldEntity $entity): bool
    {
        return $this->entity === null || $this->entity === $entity;
    }

    /**
     * @param  Builder<EmailTemplate>  $query
     * @return Builder<EmailTemplate>
     */
    protected function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * Templates offered for the given entity: those scoped to it plus those
     * without an entity.
     *
     * @param  Builder<EmailTemplate>  $query
     * @return Builder<EmailTemplate>
     */
    protected function scopeForEntity(Builder $query, CustomFieldEntity $entity): Builder
    {
        return $query->where(function (Builder $nested) use ($entity): void {
            $nested->whereNull($nested->qualifyColumn('entity'))
                ->orWhere($nested->qualifyColumn('entity'), $entity->value);
        });
    }

    private function localised(string $attribute, string $locale): string
    {
        $preferred = (string) $this->getAttribute($attribute.($locale === 'ar' ? '_ar' : '_en'));
        $other = (string) $this->getAttribute($attribute.($locale === 'ar' ? '_en' : '_ar'));

        return $preferred !== '' ? $preferred : $other;
    }
}
