<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Enums\AccountType;
use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\DealStatus;
use App\Enums\LeadStatusKind;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Models\Account;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\EmailTemplate;
use App\Models\Industry;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\LeadStatusLog;
use App\Models\Note;
use App\Models\NotificationPreference;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Product;
use App\Models\SavedView;
use App\Models\Setting;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\DemoRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * `app:demo-data` (plan step 13): never in production, seeds through the
 * real workflows with every invariant and audit row in place, refuses a
 * second run, removes exactly what it created with --fresh, and multiplies
 * the volume with --scale.
 */
final class DemoDataCommandTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('crm.attachments.disk'));
        $this->seed(DatabaseSeeder::class);
    }

    #[Test]
    public function it_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->artisan('app:demo-data', ['--force' => true])
            ->expectsOutputToContain('never seeded in production')
            ->assertFailed();

        $this->artisan('app:demo-data', ['--fresh' => true, '--force' => true])
            ->expectsOutputToContain('never seeded in production')
            ->assertFailed();

        $this->assertFalse(User::withTrashed()->where('email', 'like', '%@demo.crm.test')->exists());
        $this->assertFalse(app(DemoRegistry::class)->exists());
    }

    #[Test]
    public function it_is_cancelled_without_confirmation(): void
    {
        $this->artisan('app:demo-data')
            ->expectsConfirmation('Seed demo data (scale 1) into the "'.config('database.connections.mysql.database').'" database?', 'no')
            ->expectsOutputToContain('Cancelled')
            ->assertFailed();

        $this->assertSame(0, Lead::query()->count());
    }

    #[Test]
    public function it_seeds_a_bilingual_dataset_through_the_workflows(): void
    {
        $this->assertSame(0, Artisan::call('app:demo-data', ['--force' => true]));
        $output = Artisan::output();

        $this->assertFalse(Carbon::hasTestNow(), 'the clock was not restored');
        $this->assertFalse(Auth::guard()->hasUser(), 'a demo user was left signed in');

        // Users: one active demo user per role, a manager at the head of each of the two teams.
        $demoUsers = User::query()->with('roles')->where('email', 'like', '%@demo.crm.test')->get();
        $this->assertCount(8, $demoUsers);
        $this->assertTrue($demoUsers->every(static fn (User $user): bool => $user->status === UserStatus::Active));

        foreach (CrmRole::cases() as $role) {
            $this->assertTrue($demoUsers->contains(static fn (User $user): bool => $user->roles->contains('name', $role->value)), "no demo user holds {$role->value}");
        }

        $this->assertSame(2, Team::query()->whereNotNull('manager_user_id')->count());

        // The password is printed once and stored nowhere but as the users' hash.
        $this->assertSame(1, preg_match('/stored nowhere\): ([A-Za-z0-9]{16})/', $output, $match));
        $this->assertTrue(Hash::check($match[1], (string) $demoUsers->firstOrFail()->getAuthPassword()));
        $this->assertFalse(Setting::query()->get()->contains(static fn (Setting $setting): bool => str_contains((string) json_encode($setting->value), $match[1])));
        $this->assertFalse(DB::table('activity_log')->where('properties', 'like', '%'.$match[1].'%')->exists());

        // Leads in every status kind, qualified ones carrying their note.
        $this->assertSame(40, Lead::query()->count());

        foreach (LeadStatusKind::cases() as $kind) {
            $this->assertTrue(
                Lead::query()->whereIn('lead_status_id', LeadStatus::query()->where('kind', $kind->value)->select('id'))->exists(),
                "no demo lead in a status of kind {$kind->value}",
            );
        }

        $qualifiedStatuses = LeadStatus::query()->where('kind', LeadStatusKind::Qualified->value)->pluck('id');
        $this->assertTrue(LeadStatusLog::query()->whereIn('to_status_id', $qualifiedStatuses)->whereNotNull('notes')->exists());
        $this->assertFalse(LeadStatusLog::query()->whereIn('to_status_id', $qualifiedStatuses)->whereNull('notes')->exists());
        $this->assertFalse(Lead::query()->whereIn('lead_status_id', $qualifiedStatuses)->whereNull('qualified_at')->exists());

        // A converted lead produced its account, contact and deal.
        $converted = Lead::query()->whereNotNull('converted_deal_id')->whereNotNull('converted_account_id')->firstOrFail();
        $this->assertNotNull($converted->converted_at);
        $this->assertTrue(Account::query()->whereKey($converted->converted_account_id)->exists());
        $this->assertSame((int) $converted->getKey(), (int) Contact::query()->findOrFail($converted->converted_contact_id)->lead_id);
        $this->assertSame((int) $converted->getKey(), (int) Deal::query()->findOrFail($converted->converted_deal_id)->lead_id);

        // Accounts, contacts and deals in the volume the dataset promises.
        $this->assertGreaterThanOrEqual(12, Account::query()->count());
        $this->assertGreaterThanOrEqual(30, Contact::query()->count());
        $this->assertGreaterThanOrEqual(25, Deal::query()->count());
        $this->assertTrue(Account::query()->where('name', 'like', '%شركة%')->exists());
        $this->assertTrue(Account::query()->where('name', 'like', '%Company%')->exists());

        // Won deals promoted their prospect accounts; lost deals carry a lost reason.
        $won = Deal::query()->where('status', DealStatus::Won->value)->get();
        $this->assertGreaterThanOrEqual(5, $won->count());
        $this->assertFalse(Account::query()->whereIn('id', $won->pluck('account_id')->filter())->where('type', AccountType::Prospect->value)->exists());
        $this->assertTrue(DB::table('activity_log')->where('description', ActivityLogEvent::AccountBecameCustomer->value)->exists());
        $this->assertFalse(Deal::query()->where('status', '!=', DealStatus::Open->value)->whereNull('close_reason_id')->exists());
        $this->assertTrue(Deal::query()->where('status', DealStatus::Lost->value)->exists());
        $this->assertTrue(DB::table('deal_stage_logs')->exists());

        // Line items drive the amount of the deals that have them (D-8).
        $withLines = Deal::query()->whereHas('products')->withSum('products', 'line_total')->get();
        $this->assertNotEmpty($withLines);

        foreach ($withLines as $deal) {
            $this->assertSame(number_format((float) $deal->getAttribute('products_sum_line_total'), 2, '.', ''), $deal->amount);
        }

        // The ledger recorded the workflows with a causer.
        foreach ([ActivityLogEvent::LeadCreated, ActivityLogEvent::LeadQualified, ActivityLogEvent::LeadConverted, ActivityLogEvent::DealWon, ActivityLogEvent::DealLost, ActivityLogEvent::ActivityCreated, ActivityLogEvent::TaskCompleted] as $event) {
            $this->assertTrue(DB::table('activity_log')->where('description', $event->value)->whereNotNull('causer_id')->exists(), "no audit row for {$event->value}");
        }

        // History spread over the past, none of it in the future.
        $this->assertTrue(Lead::query()->where('created_at', '<', now()->subDays(60))->exists());
        $this->assertFalse(DB::table('activity_log')->where('created_at', '>', now())->exists());
        $this->assertFalse(Activity::query()->where('occurred_at', '>', now())->exists());

        // Tasks: open, overdue, completed and a recurring series.
        $this->assertTrue(Task::query()->where('status', TaskStatus::Pending->value)->where('due_at', '>', now())->exists());
        $this->assertTrue(Task::query()->where('status', TaskStatus::Pending->value)->where('due_at', '<', now())->exists());
        $this->assertTrue(Task::query()->where('status', TaskStatus::Completed->value)->whereNotNull('completed_at')->exists());
        $this->assertTrue(Task::query()->whereNotNull('series_id')->where('status', TaskStatus::Pending->value)->exists());

        // Activities over the last sixty days, notes, files, views and preferences.
        $this->assertGreaterThanOrEqual(60, Activity::query()->where('occurred_at', '>=', now()->subDays(61))->count());
        $this->assertTrue(Note::query()->where('is_pinned', true)->exists());
        $this->assertSame(2, Attachment::query()->count());

        foreach (Attachment::query()->get() as $attachment) {
            Storage::disk($attachment->disk)->assertExists($attachment->path);
        }

        $this->assertEqualsCanonicalizing(['application/pdf', 'image/png'], Attachment::query()->pluck('mime_type')->all());
        $this->assertSame(2, SavedView::query()->count());
        $this->assertTrue(NotificationPreference::query()->exists());
        $this->assertTrue(DB::table('notifications')->exists());

        $this->assertTrue(app(DemoRegistry::class)->exists());
    }

    #[Test]
    public function it_refuses_a_second_run_while_demo_data_exists(): void
    {
        $this->artisan('app:demo-data', ['--force' => true])->assertSuccessful();
        $leads = Lead::query()->count();

        $this->artisan('app:demo-data', ['--force' => true])
            ->expectsOutputToContain('Run `php artisan app:demo-data --fresh` first')
            ->assertFailed();

        $this->assertSame($leads, Lead::query()->count());
    }

    #[Test]
    public function fresh_removes_everything_the_run_created_and_keeps_real_users_and_reference_data(): void
    {
        $real = $this->makeUser(CrmRole::SalesRep, ['email' => 'real.rep@example.com']);
        $reference = $this->referenceCounts();
        $auditBefore = DB::table('activity_log')->count();

        $this->artisan('app:demo-data', ['--force' => true])->assertSuccessful();
        $files = Attachment::query()->get(['disk', 'path'])->all();
        $this->assertCount(2, $files);

        $this->artisan('app:demo-data', ['--fresh' => true, '--force' => true])
            ->expectsOutputToContain('Demo data removed')
            ->assertSuccessful();

        $this->assertSame([(int) $real->getKey()], User::withTrashed()->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
        $this->assertTrue($real->fresh()?->hasRole(CrmRole::SalesRep->value));

        foreach (['accounts', 'contacts', 'leads', 'deals', 'deal_products', 'deal_stage_logs', 'lead_status_logs', 'activities', 'tasks', 'notes', 'attachments', 'saved_views', 'notification_preferences', 'notifications', 'products', 'teams'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} still has demo rows");
        }

        $this->assertSame(0, DB::table('exports')->count(), 'exports still has demo rows');
        $this->assertSame(
            0,
            DB::table('model_has_roles')->where('model_type', (new User)->getMorphClass())->where('model_id', '!=', $real->getKey())->count(),
            'a removed demo user still holds a role',
        );
        $this->assertSame($auditBefore, DB::table('activity_log')->count());
        $this->assertSame($reference, $this->referenceCounts());
        $this->assertFalse(app(DemoRegistry::class)->exists());

        foreach ($files as $file) {
            Storage::disk($file->disk)->assertMissing($file->path);
        }

        // A second --fresh has nothing to do; a new run is allowed again.
        $this->artisan('app:demo-data', ['--fresh' => true, '--force' => true])
            ->expectsOutputToContain('Nothing to remove')
            ->assertSuccessful();
    }

    #[Test]
    public function fresh_refuses_while_a_real_record_depends_on_demo_data(): void
    {
        $this->artisan('app:demo-data', ['--force' => true])->assertSuccessful();

        $real = $this->makeUser(CrmRole::SalesRep, ['email' => 'real.rep@example.com']);
        $account = Account::query()->where('owner_id', User::query()->where('email', 'sales_rep@demo.crm.test')->value('id'))->firstOrFail();
        Contact::query()->create(['account_id' => $account->getKey(), 'first_name' => 'Real', 'last_name' => 'Person', 'owner_id' => $real->getKey()]);
        $leads = Lead::query()->count();

        $this->artisan('app:demo-data', ['--fresh' => true, '--force' => true])
            ->expectsOutputToContain('1 contact(s) not created by the demo belong to a demo account')
            ->assertFailed();

        $this->assertSame($leads, Lead::query()->count());
        $this->assertTrue(app(DemoRegistry::class)->exists());
    }

    #[Test]
    public function fresh_refuses_while_records_the_demo_did_not_create_reference_a_demo_user_and_allow_orphans_overrides_it(): void
    {
        $this->artisan('app:demo-data', ['--force' => true])->assertSuccessful();

        // What an import run as the demo admin leaves behind: two contacts owned and created by that user, and the run.
        $demoAdmin = (int) User::query()->where('email', 'admin@demo.crm.test')->value('id');
        $imported = collect([['Imported', 'One'], ['Imported', 'Two']])->map(fn (array $name): Contact => Contact::query()->forceCreate([
            'first_name' => $name[0], 'last_name' => $name[1], 'owner_id' => $demoAdmin, 'created_by' => $demoAdmin,
        ]));
        DB::table('imports')->insert([
            'file_name' => 'contacts.csv', 'file_path' => 'livewire-tmp/contacts.csv', 'importer' => 'App\Filament\Imports\ContactImporter',
            'total_rows' => 2, 'processed_rows' => 2, 'successful_rows' => 2, 'user_id' => $demoAdmin, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $users = User::query()->count();

        $this->assertSame(1, Artisan::call('app:demo-data', ['--fresh' => true, '--force' => true]));
        $refused = Artisan::output();

        $this->assertStringContainsString('The demo users cannot be removed', $refused);
        $this->assertStringContainsString('2 contacts row(s) not created by the demo have created_by set to a demo user (it would be set to NULL)', $refused);
        $this->assertStringContainsString('2 contacts row(s) not created by the demo have owner_id set to a demo user (it would be set to NULL)', $refused);
        $this->assertStringContainsString('1 imports row(s) not created by the demo have user_id set to a demo user (the rows would be deleted)', $refused);
        $this->assertStringContainsString('--allow-orphans', $refused);

        $this->assertSame($users, User::query()->count(), 'nothing is removed while the references exist');
        $this->assertTrue(app(DemoRegistry::class)->exists());

        $this->assertSame(0, Artisan::call('app:demo-data', ['--fresh' => true, '--force' => true, '--allow-orphans' => true]));
        $removed = Artisan::output();

        $this->assertStringContainsString('2 contacts row(s) not created by the demo have owner_id set to a demo user', $removed);
        $this->assertStringContainsString('the records listed above were orphaned or deleted as described', $removed);

        $this->assertFalse(User::withTrashed()->whereKey($demoAdmin)->exists());
        $this->assertFalse(app(DemoRegistry::class)->exists());
        $this->assertSame(0, DB::table('imports')->count());

        foreach ($imported as $contact) {
            $survivor = Contact::withTrashed()->find($contact->getKey());
            $this->assertNotNull($survivor, 'a record the demo did not create is never deleted');
            $this->assertNull($survivor->owner_id);
            $this->assertNull($survivor->created_by);
        }
    }

    #[Test]
    public function fresh_lists_audit_entries_a_demo_user_caused_outside_the_recorded_run(): void
    {
        $this->artisan('app:demo-data', ['--force' => true])->assertSuccessful();

        $demoRep = User::query()->where('email', 'sales_rep@demo.crm.test')->firstOrFail();
        DB::table('activity_log')->insert([
            'log_name' => 'default', 'description' => 'exported', 'event' => 'exported',
            'causer_type' => $demoRep->getMorphClass(), 'causer_id' => $demoRep->getKey(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('app:demo-data', ['--fresh' => true, '--force' => true])
            ->expectsOutputToContain('1 activity_log row(s) not created by the demo are caused by a demo user')
            ->assertFailed();
    }

    #[Test]
    public function scale_multiplies_the_volume(): void
    {
        $tables = ['accounts', 'contacts', 'leads', 'deals', 'tasks', 'activities'];

        $this->artisan('app:demo-data', ['--force' => true])->assertSuccessful();
        $single = array_intersect_key(app(DemoRegistry::class)->counts(), array_flip($tables));
        $users = User::query()->count();

        $this->artisan('app:demo-data', ['--fresh' => true, '--force' => true])->assertSuccessful();
        $this->artisan('app:demo-data', ['--force' => true, '--scale' => '2'])->assertSuccessful();
        $double = array_intersect_key(app(DemoRegistry::class)->counts(), array_flip($tables));

        foreach ($tables as $table) {
            $this->assertGreaterThan(0, $single[$table]);
            $this->assertSame($single[$table] * 2, $double[$table], "{$table} did not double");
            $this->assertSame($double[$table], DB::table($table)->count());
        }

        $this->assertSame($users, User::query()->count(), 'the demo users are not multiplied');
    }

    #[Test]
    public function an_invalid_scale_is_refused(): void
    {
        $this->artisan('app:demo-data', ['--force' => true, '--scale' => '0'])
            ->expectsOutputToContain('--scale must be a whole number')
            ->assertFailed();

        $this->artisan('app:demo-data', ['--force' => true, '--scale' => 'x'])->assertFailed();
        $this->assertSame(0, Lead::query()->count());
    }

    /**
     * @return array<string, int>
     */
    private function referenceCounts(): array
    {
        return [
            'roles' => Role::query()->count(),
            'permissions' => Permission::query()->count(),
            'role_has_permissions' => DB::table('role_has_permissions')->count(),
            'lead_statuses' => LeadStatus::query()->count(),
            'lead_sources' => LeadSource::query()->count(),
            'industries' => Industry::query()->count(),
            'pipelines' => Pipeline::query()->count(),
            'pipeline_stages' => PipelineStage::query()->count(),
            'activity_types' => ActivityType::query()->count(),
            'deal_close_reasons' => DealCloseReason::query()->count(),
            'email_templates' => EmailTemplate::query()->count(),
            'settings' => Setting::query()->count(),
            'products' => Product::query()->count(),
        ];
    }
}
