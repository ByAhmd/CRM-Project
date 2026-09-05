<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\ActivityLogEvent;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use App\Services\Audit\ActivityLogPresenter;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

final class ActivityLogResourceTest extends TestCase
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
    public function the_ledger_is_visible_to_audit_viewers_only(): void
    {
        $admin = $this->admin();
        $manager = $this->salesManager();

        $this->actingAs($admin)->get(ActivityLogResource::getUrl('index'))->assertOk();
        $this->actingAs($manager)->get(ActivityLogResource::getUrl('index'))->assertForbidden();
        $this->assertFalse(ActivityLogResource::canCreate());
    }

    #[Test]
    public function rows_are_listed_newest_first_with_translated_labels(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();

        app(AuditLogger::class)->record(ActivityLogEvent::UserInvited, $rep, $admin, ['subject_label' => $rep->name, 'email' => $rep->email]);

        $row = ActivityLog::query()->where('description', ActivityLogEvent::UserInvited->value)->firstOrFail();
        $presenter = app(ActivityLogPresenter::class)->for($row);

        app()->setLocale('ar');
        $this->assertSame('إرسال دعوة', $presenter->actionLabel());
        $this->assertSame($rep->name, $presenter->subjectLabel());
        $this->assertSame($admin->name, $presenter->causerLabel());

        app()->setLocale('en');
        $this->assertSame('Invitation sent', $presenter->actionLabel());
        app()->setLocale('ar');

        Livewire::actingAs($admin)
            ->test(ListActivityLogs::class)
            ->assertCanSeeTableRecords([$row])
            ->assertTableActionVisible('details', $row)
            ->callTableAction('details', $row);
    }

    #[Test]
    public function the_details_modal_renders_attribute_diffs_from_the_ledger(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();

        $this->actingAs($admin);
        $rep->update(['name' => 'Renamed Rep']);

        $row = ActivityLog::query()
            ->where('description', ActivityLogEvent::UserUpdated->value)
            ->where('subject_id', $rep->getKey())
            ->latest('id')
            ->firstOrFail();

        $rows = app(ActivityLogPresenter::class)->for($row)->detailRows();
        $labels = array_column($rows, 'label');
        $values = array_column($rows, 'value');

        $this->assertContains(__('activity.attributes.name'), $labels);
        $this->assertContains(__('activity.details.from_to', ['old' => 'Sales Rep', 'new' => 'Renamed Rep']), $values);
    }
}
