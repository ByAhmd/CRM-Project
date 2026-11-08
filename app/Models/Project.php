<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BadgeColor;
use App\Models\Concerns\AuditsAsLookup;
use App\Models\Concerns\HasLocalisedName;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A project (decision D-30): one partition of the customer base — a business
 * line or brand with its own leads, contacts, accounts and deals.
 *
 * Configurable business data like every lookup (A-4): bilingual name, badge
 * colour, active / inactive, exactly one default (the project new users start
 * in and existing records were assigned to), an order for the switcher and
 * the cards. The records reference it with RESTRICT keys, so a project in use
 * can only be deactivated; ProjectPolicy refuses the delete and the default
 * can neither be deleted nor deactivated (ProjectService keeps one default).
 *
 * @property BadgeColor $color
 * @property bool $is_active
 * @property bool $is_default
 * @property-read string $display_name
 */
#[Fillable(['name_ar', 'name_en', 'color', 'is_active', 'is_default', 'sort'])]
final class Project extends Model
{
    use AuditsAsLookup;

    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use HasLocalisedName;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'color' => BadgeColor::class,
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public static function auditedAttributes(): array
    {
        return ['name_ar', 'name_en', 'color', 'is_active', 'is_default', 'sort'];
    }

    /**
     * The active projects in switcher order: by `sort`, then by the name in
     * the current locale, so a tie reads alphabetically.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('is_active'), true)
            ->orderBy($query->qualifyColumn('sort'))
            ->orderBy($query->qualifyColumn(self::localisedNameColumn()));
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'project_id');
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'project_id');
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'project_id');
    }

    /**
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'project_id');
    }
}
