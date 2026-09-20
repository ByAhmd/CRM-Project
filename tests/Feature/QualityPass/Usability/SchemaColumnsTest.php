<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Usability;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Pipelines\Schemas\PipelineStageForm;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\CustomField;
use App\Models\Pipeline;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The section column convention (A-22), held across every form and infolist
 * schema of every registered resource rather than against a list of file
 * names, so a section added later cannot fall back to an implicit layout.
 *
 * Every Section declares its column map explicitly: the responsive map
 * `['default' => 1, 'lg' => 2]` where short fields pair, or the deliberate
 * single column `->columns(1)` for a section whose content is one field, a
 * repeater, a long text or a grid that manages its own density. What the test
 * can prove is the declaration, not the taste: a section without any
 * `->columns()` call, or with a responsive array that forgot the `default`
 * key phones depend on, fails here.
 *
 * Filament stores `->columns(1)` as `['lg' => 1]` internally, so the two
 * accepted shapes read back as: an array whose only key is `lg` with an
 * integer value (the int declaration), or an array carrying a `default` key
 * (the responsive map).
 */
final class SchemaColumnsTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    #[Test]
    public function every_section_of_every_form_and_infolist_declares_its_columns_explicitly(): void
    {
        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->actingAs($this->superAdmin());

        // One active definition per entity — one of them long-form — so the
        // injected custom-field sections (D-9) exist and are walked too.
        foreach (CustomFieldEntity::cases() as $entity) {
            CustomField::factory()->forEntity($entity)->create();
        }

        CustomField::factory()
            ->forEntity(CustomFieldEntity::Lead)
            ->ofType(CustomFieldType::Textarea)
            ->create();

        $offenders = [];
        $sections = 0;

        foreach ($this->schemas() as $label => $schema) {
            foreach ($this->sectionsOf($schema->getComponents(withActions: false, withHidden: true)) as $section) {
                $sections++;
                $failure = $this->declarationFailure($section);

                if ($failure !== null) {
                    $offenders[] = "{$label} [{$this->headingOf($section)}]: {$failure}";
                }
            }
        }

        $this->assertGreaterThan(80, $sections, 'sanity: the walk covers the panel form and infolist sections');
        $this->assertSame([], $offenders, "sections without an explicit column declaration:\n".implode("\n", $offenders));
    }

    /**
     * The declaration test above is satisfied by the OLD single-column tree
     * too — every section already declared ->columns(1) before A-22 — so on
     * its own it would stay green through a wholesale revert. This is the
     * half with teeth: the desktop pairing must actually exist, in bulk and
     * on every core entity, or the layout has regressed.
     */
    #[Test]
    public function the_core_schemas_actually_pair_fields_on_large_screens(): void
    {
        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->actingAs($this->superAdmin());

        foreach (CustomFieldEntity::cases() as $entity) {
            CustomField::factory()->forEntity($entity)->create();
        }

        $pairedTotal = 0;
        $pairedBySchema = [];

        foreach ($this->schemas() as $label => $schema) {
            foreach ($this->sectionsOf($schema->getComponents(withActions: false, withHidden: true)) as $section) {
                if ($this->pairsAtLg($section)) {
                    $pairedTotal++;
                    $pairedBySchema[$label] = ($pairedBySchema[$label] ?? 0) + 1;
                }
            }
        }

        $this->assertGreaterThan(
            30,
            $pairedTotal,
            "only {$pairedTotal} sections pair their fields at lg — the two-column desktop layout (A-22) has been reverted or gutted",
        );

        // Every core entity keeps at least one paired section on BOTH its
        // form and its view page; a per-entity revert fails by name.
        foreach ([
            LeadResource::class,
            ContactResource::class,
            AccountResource::class,
            DealResource::class,
            TaskResource::class,
        ] as $resource) {
            foreach (['form', 'infolist'] as $kind) {
                $this->assertGreaterThanOrEqual(
                    1,
                    $pairedBySchema[$resource.'::'.$kind] ?? 0,
                    "{$resource}::{$kind} no longer pairs any section at lg (A-22)",
                );
            }
        }
    }

    /**
     * Whether this section actually spreads fields across the row on a large
     * screen — either through the A-22 section map (default 1, lg 2) or
     * through an inner responsive Grid (TaskInfolist packs four short entries
     * per row that way, which is denser than the convention, not a violation).
     * Both mechanisms keep phones single-column, which is what `default` and
     * Grid::make(int)'s implicit default-of-1 guarantee.
     */
    private function pairsAtLg(Section $section): bool
    {
        $columns = $section->getColumns();

        if (is_array($columns) && ($columns['default'] ?? null) === 1 && (int) ($columns['lg'] ?? 0) >= 2) {
            return true;
        }

        foreach ($this->gridsOf($section->getDefaultChildComponents()) as $grid) {
            $gridColumns = $grid->getColumns();

            if (is_array($gridColumns) && (int) ($gridColumns['lg'] ?? 0) >= 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every Grid among the components, one level deep or nested.
     *
     * @param  array<mixed>  $components
     * @return list<Grid>
     */
    private function gridsOf(array $components): array
    {
        $grids = [];

        foreach ($components as $component) {
            if ($component instanceof Grid) {
                $grids[] = $component;
            }

            if (method_exists($component, 'getDefaultChildComponents')) {
                $grids = [...$grids, ...$this->gridsOf($component->getDefaultChildComponents())];
            }
        }

        return $grids;
    }

    /**
     * Why the section's column declaration violates the convention, or null
     * when it complies.
     */
    private function declarationFailure(Section $section): ?string
    {
        if (! $section->hasCustomColumns()) {
            return 'no ->columns() declaration; declare ->columns([\'default\' => 1, \'lg\' => 2]) for paired short fields or ->columns(1) deliberately';
        }

        $columns = $section->getColumns();

        if (! is_array($columns)) {
            return 'the column declaration did not resolve to an array';
        }

        if (array_key_exists('default', $columns)) {
            return null;
        }

        if (array_keys($columns) === ['lg'] && is_int($columns['lg'] ?? null)) {
            return null;
        }

        return 'the responsive column map carries no \'default\' key, so the phone layout is implicit';
    }

    /**
     * Every form and infolist schema of the panel, plus the stage form, which
     * is served by the pipeline's relation manager rather than a resource page
     * and would otherwise escape the walk.
     *
     * @return array<string, Schema>
     */
    private function schemas(): array
    {
        $schemas = [];

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $schemas[$resource.'::form'] = $resource::form(Schema::make());
            $schemas[$resource.'::infolist'] = $resource::infolist(Schema::make());
        }

        $schemas[PipelineStageForm::class] = PipelineStageForm::configure(
            Schema::make(),
            Pipeline::query()->firstOrFail(),
        );

        return $schemas;
    }

    /**
     * Every Section in the component tree, however deeply nested in grids,
     * wizards or repeaters. Only the declared default child components are
     * followed — visibility closures and record state are never evaluated, so
     * a section hidden on create or behind a permission is still checked.
     *
     * @param  array<mixed>  $components
     * @return list<Section>
     */
    private function sectionsOf(array $components): array
    {
        $sections = [];

        foreach ($components as $component) {
            if (! $component instanceof Component) {
                continue;
            }

            if ($component instanceof Section) {
                $sections[] = $component;
            }

            $children = $component->getDefaultChildComponents();

            if ($children instanceof Schema) {
                $children = $children->getComponents(withActions: false, withHidden: true);
            }

            $sections = [...$sections, ...$this->sectionsOf($children)];
        }

        return $sections;
    }

    private function headingOf(Section $section): string
    {
        $heading = $section->getHeading();

        if ($heading instanceof Htmlable) {
            return $heading->toHtml();
        }

        return $heading ?? '(no heading)';
    }
}
