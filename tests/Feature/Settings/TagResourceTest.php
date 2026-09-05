<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\ActivityLogEvent;
use App\Enums\BadgeColor;
use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Filament\Resources\Tags\Pages\EditTag;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Filament\Resources\Tags\TagResource;
use App\Models\Tag;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Support\TaggableStub;
use Tests\TestCase;

/**
 * Tags are a bilingual settings lookup (A-4) with a colour and a keyless
 * polymorphic pivot every taggable entity reaches through HasTags.
 */
final class TagResourceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
    }

    #[Test]
    public function admins_list_tags_and_sales_managers_are_refused(): void
    {
        $admin = $this->admin();
        $manager = $this->salesManager();
        $tag = $this->makeTag();

        $this->actingAs($admin)->get(TagResource::getUrl('index'))->assertOk();
        $this->actingAs($admin)->get(TagResource::getUrl('create'))->assertOk();
        $this->actingAs($manager)->get(TagResource::getUrl('index'))->assertForbidden();
        $this->actingAs($manager)->get(TagResource::getUrl('create'))->assertForbidden();

        Livewire::actingAs($admin)->test(ListTags::class)->assertCanSeeTableRecords([$tag]);
    }

    #[Test]
    public function a_tag_is_created_with_both_names_and_a_colour_and_audited(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateTag::class)
            ->fillForm([
                'name_ar' => 'عميل مهم',
                'name_en' => 'VIP',
                'color' => BadgeColor::Warning->value,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tag = Tag::query()->where('name_en', 'VIP')->firstOrFail();

        $this->assertSame('عميل مهم', $tag->name_ar);
        $this->assertSame(BadgeColor::Warning, $tag->color);
        $this->assertTrue($tag->is_active);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupCreated->value,
            'log_name' => ActivityLogEvent::LookupCreated->logName(),
            'subject_type' => Tag::class,
            'subject_id' => $tag->getKey(),
        ]);
    }

    #[Test]
    public function a_tag_is_edited_and_the_change_is_audited(): void
    {
        $admin = $this->admin();
        $tag = $this->makeTag();

        Livewire::actingAs($admin)
            ->test(EditTag::class, ['record' => $tag->getRouteKey()])
            ->fillForm([
                'name_en' => 'Renamed',
                'color' => BadgeColor::Danger->value,
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $tag->refresh();

        $this->assertSame('Renamed', $tag->name_en);
        $this->assertSame(BadgeColor::Danger, $tag->color);
        $this->assertFalse($tag->is_active);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupUpdated->value,
            'subject_type' => Tag::class,
            'subject_id' => $tag->getKey(),
        ]);
    }

    #[Test]
    public function both_names_are_required_and_unique(): void
    {
        $admin = $this->admin();
        $this->makeTag('Hot', 'ساخن');

        Livewire::actingAs($admin)
            ->test(CreateTag::class)
            ->fillForm(['name_ar' => 'ساخن', 'name_en' => ''])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'unique', 'name_en' => 'required']);

        Livewire::actingAs($admin)
            ->test(CreateTag::class)
            ->fillForm(['name_ar' => '', 'name_en' => 'Hot'])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'required', 'name_en' => 'unique']);
    }

    #[Test]
    public function editing_a_tag_ignores_its_own_names_for_uniqueness(): void
    {
        $admin = $this->admin();
        $tag = $this->makeTag('Hot', 'ساخن');

        Livewire::actingAs($admin)
            ->test(EditTag::class, ['record' => $tag->getRouteKey()])
            ->fillForm(['name_ar' => 'ساخن', 'name_en' => 'Hot', 'color' => BadgeColor::Info->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(BadgeColor::Info, $tag->refresh()->color);
    }

    #[Test]
    public function the_display_name_follows_the_locale(): void
    {
        $tag = $this->makeTag('Hot', 'ساخن');

        app()->setLocale('ar');
        $this->assertSame('ساخن', $tag->display_name);
        $this->assertSame('name_ar', Tag::localisedNameColumn());

        app()->setLocale('en');
        $this->assertSame('Hot', $tag->display_name);
        $this->assertSame('name_en', Tag::localisedNameColumn());

        app()->setLocale('ar');
    }

    #[Test]
    public function the_colour_defaults_to_gray_and_is_constrained_at_the_database(): void
    {
        $tag = Tag::query()->create(['name_ar' => 'عادي', 'name_en' => 'Regular']);

        $this->assertSame(BadgeColor::Gray, $tag->refresh()->color);

        $this->expectException(QueryException::class);

        DB::table('tags')->insert([
            'name_ar' => 'بنفسجي',
            'name_en' => 'Purple',
            'color' => 'purple',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function active_options_list_only_active_tags_in_the_reader_language(): void
    {
        $vip = $this->makeTag('VIP', 'عميل مهم');
        $hot = $this->makeTag('Hot', 'ساخن');
        $this->makeTag('Archived', 'مؤرشف', isActive: false);

        app()->setLocale('ar');
        $this->assertSame([$hot->getKey() => 'ساخن', $vip->getKey() => 'عميل مهم'], Tag::activeOptions());

        app()->setLocale('en');
        $this->assertSame([$hot->getKey() => 'Hot', $vip->getKey() => 'VIP'], Tag::activeOptions());

        app()->setLocale('ar');
    }

    #[Test]
    public function a_tag_is_deleted_from_its_edit_page_and_audited(): void
    {
        $admin = $this->admin();
        $tag = $this->makeTag();

        Livewire::actingAs($admin)
            ->test(EditTag::class, ['record' => $tag->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('tags', ['id' => $tag->getKey()]);
        $this->assertDatabaseHas('activity_log', [
            'description' => ActivityLogEvent::LookupDeleted->value,
            'subject_type' => Tag::class,
            'subject_id' => $tag->getKey(),
        ]);
    }

    #[Test]
    public function tags_attach_through_the_trait_once_per_record_and_follow_the_tag_when_it_is_deleted(): void
    {
        $this->createTaggableStubsTable();

        $vip = $this->makeTag('VIP', 'عميل مهم');
        $hot = $this->makeTag('Hot', 'ساخن');
        $record = TaggableStub::query()->create();
        $other = TaggableStub::query()->create();

        $record->tags()->attach([$vip->getKey(), $hot->getKey()]);
        $other->tags()->attach($vip->getKey());

        $this->assertEqualsCanonicalizing([$vip->getKey(), $hot->getKey()], $record->tags()->pluck('tags.id')->all());
        $this->assertDatabaseHas('taggables', [
            'tag_id' => $vip->getKey(),
            'taggable_type' => TaggableStub::class,
            'taggable_id' => $record->getKey(),
        ]);

        try {
            $record->tags()->attach($vip->getKey());
            $this->fail('the composite primary key must refuse a second attachment of the same tag');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('taggables')->where('tag_id', $vip->getKey())->where('taggable_id', $record->getKey())->count());
        }

        $vip->delete();

        $this->assertDatabaseMissing('taggables', ['tag_id' => $vip->getKey()]);
        $this->assertSame([$hot->getKey()], $record->tags()->pluck('tags.id')->all());
        $this->assertSame(0, $other->tags()->count());
    }

    private function makeTag(string $nameEn = 'Priority', string $nameAr = 'أولوية', BadgeColor $color = BadgeColor::Gray, bool $isActive = true): Tag
    {
        return Tag::factory()->create([
            'name_en' => $nameEn,
            'name_ar' => $nameAr,
            'color' => $color,
            'is_active' => $isActive,
        ]);
    }

    /**
     * A temporary table lives in the test's own connection and, unlike a real
     * CREATE TABLE, does not commit the transaction RefreshDatabase wraps the
     * test in; it disappears when the connection is dropped at tear-down.
     */
    private function createTaggableStubsTable(): void
    {
        Schema::create('taggable_stubs', function (Blueprint $table): void {
            $table->temporary();
            $table->id();
            $table->timestamps();
        });
    }
}
