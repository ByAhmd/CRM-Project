<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\NotificationEvent;
use App\Enums\Permission;
use App\Filament\Pages\System\Backups;
use App\Jobs\CreateBackup;
use App\Models\ActivityLog;
use App\Notifications\BackupFailedNotification;
use App\Services\System\BackupException;
use App\Services\System\BackupFailure;
use App\Support\Notifications\NotificationChannels;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Concerns\BuildsBackupSets;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * System → Backups and the backup download route (decision D-16): super admins
 * (`roles.manage`) only — every other seeded role is refused the page, the
 * action and every file; files are resolved against the listed sets, never
 * from a path; downloads are audited; the failure notice reads in both
 * locales and mails by default.
 */
final class BackupsPageTest extends TestCase
{
    use BuildsBackupSets;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private string $backups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
        $this->backups = $this->useBackupDirectory();
        $this->travelTo(CarbonImmutable::parse('2026-10-02 09:00:00', 'Asia/Riyadh'));
    }

    #[Test]
    public function only_the_super_admin_reaches_the_page_among_the_seeded_roles(): void
    {
        foreach (CrmRole::cases() as $role) {
            $user = $this->makeUser($role, ['name' => 'Viewer '.$role->value]);

            $this->actingAs($user)
                ->get(Backups::getUrl())
                ->assertStatus($role === CrmRole::SuperAdmin ? 200 : 403);
        }

        $this->actingAs($this->userWithPermissions(null, [Permission::RolesManage]))
            ->get(Backups::getUrl())
            ->assertOk();
    }

    #[Test]
    public function the_page_lists_every_set_newest_first_with_its_sizes_and_download_links(): void
    {
        $old = $this->makeBackupSet($this->backups, now()->subWeeks(2)->setTime(22, 0));
        $new = $this->makeBackupSet($this->backups, now()->subWeek()->setTime(22, 0), str_repeat('d', 2048), str_repeat('f', 3072));

        Livewire::actingAs($this->superAdmin())
            ->test(Backups::class)
            ->assertSeeInOrder(['2026-09-25 22:00', '2 KB', '3 KB', '5 KB', '2026-09-18 22:00'])
            ->assertSeeHtml(e(route('backups.download', ['backup' => $new, 'file' => 'database'])))
            ->assertSeeHtml(e(route('backups.download', ['backup' => $old, 'file' => 'files'])))
            ->assertSee(trans_choice('backups.helpers.schedule', 8, ['count' => 8]))
            ->assertDontSee(__('backups.empty.heading'));
    }

    #[Test]
    public function download_links_open_in_a_new_tab_so_spa_navigation_never_prefetches_or_swaps_a_dump(): void
    {
        $id = $this->makeBackupSet($this->backups, now()->subWeek()->setTime(22, 0));

        Livewire::actingAs($this->superAdmin())
            ->test(Backups::class)
            ->assertActionShouldOpenUrlInNewTab(TestAction::make('downloadDatabase')->table($id))
            ->assertActionShouldOpenUrlInNewTab(TestAction::make('downloadFiles')->table($id))
            ->assertSeeHtml('href="'.e(route('backups.download', ['backup' => $id, 'file' => 'database'])).'" target="_blank"')
            ->assertSeeHtml('href="'.e(route('backups.download', ['backup' => $id, 'file' => 'files'])).'" target="_blank"');

        $this->assertSame(0, ActivityLog::query()->where('description', ActivityLogEvent::BackupDownloaded->value)->count());
    }

    #[Test]
    public function the_exact_cause_of_a_failure_reads_in_the_readers_locale_with_the_tool_output_as_written(): void
    {
        $exception = BackupException::location('location_public', ['directory' => '/home/crm/public_html/backups']);
        file_put_contents($this->backups.DIRECTORY_SEPARATOR.'last-failure.json', (string) json_encode(
            BackupFailure::fromException($exception, now()->toImmutable())->toArray(),
        ));
        $superAdmin = $this->superAdmin();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            Livewire::actingAs($superAdmin)
                ->test(Backups::class)
                ->assertSee(__('backups.details.location_public', ['directory' => '/home/crm/public_html/backups']));
        }

        app()->setLocale('ar');
        $this->assertStringNotContainsString('lies under public/', __('backups.details.location_public', ['directory' => '/x']), 'Arabic readers get Arabic');

        $toolFailure = BackupException::database('tool_failed', ['tool' => 'mysqldump', 'code' => '2', 'output' => 'Access denied for user']);
        file_put_contents($this->backups.DIRECTORY_SEPARATOR.'last-failure.json', (string) json_encode(
            BackupFailure::fromException($toolFailure, now()->toImmutable())->toArray(),
        ));

        Livewire::actingAs($superAdmin)
            ->test(Backups::class)
            ->assertSee(__('backups.details.tool_failed', ['tool' => 'mysqldump', 'code' => '2', 'output' => 'Access denied for user']))
            ->assertSee('Access denied for user');
    }

    #[Test]
    public function an_empty_directory_shows_the_translated_empty_state(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(Backups::class)
            ->assertSee(__('backups.empty.heading'))
            ->assertSee(__('backups.empty.description'));
    }

    #[Test]
    public function the_last_failure_is_shown_with_its_translated_reason_and_its_detail(): void
    {
        file_put_contents($this->backups.DIRECTORY_SEPARATOR.'last-failure.json', (string) json_encode(
            (new BackupFailure(now()->toImmutable(), BackupException::REASON_DATABASE, 'mysqldump exited with code 2: Access denied'))->toArray(),
        ));

        Livewire::actingAs($this->superAdmin())
            ->test(Backups::class)
            ->assertSee(__('backups.pages.last_failure', ['time' => '2026-10-02 09:00', 'reason' => __('backups.reasons.database')]))
            ->assertSee('mysqldump exited with code 2: Access denied');
    }

    #[Test]
    public function back_up_now_queues_one_run_attributed_to_the_super_admin(): void
    {
        Queue::fake();
        $superAdmin = $this->superAdmin();

        Livewire::actingAs($superAdmin)
            ->test(Backups::class)
            ->assertActionVisible('backUpNow')
            ->callAction('backUpNow')
            ->assertNotified(__('backups.notifications.queued_title'));

        Queue::assertPushed(CreateBackup::class, fn (CreateBackup $job): bool => $job->requestedBy === $superAdmin->getKey());
    }

    #[Test]
    public function every_other_seeded_role_is_refused_the_page_component_and_its_action(): void
    {
        Queue::fake();

        foreach (CrmRole::cases() as $role) {
            if ($role === CrmRole::SuperAdmin) {
                continue;
            }

            Livewire::actingAs($this->makeUser($role, ['name' => 'Refused '.$role->value]))
                ->test(Backups::class)
                ->assertForbidden();
        }

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_file_downloads_only_for_the_super_admin_and_every_download_is_audited(): void
    {
        $id = $this->makeBackupSet($this->backups, now()->subDay(), 'gzip dump bytes', 'tar archive bytes');
        $superAdmin = $this->superAdmin();

        foreach (CrmRole::cases() as $role) {
            if ($role === CrmRole::SuperAdmin) {
                continue;
            }

            $this->actingAs($this->makeUser($role, ['name' => 'Downloader '.$role->value]))
                ->get(route('backups.download', ['backup' => $id, 'file' => 'database']))
                ->assertForbidden();
        }

        $response = $this->actingAs($superAdmin)->get(route('backups.download', ['backup' => $id, 'file' => 'database']));

        $response->assertOk()->assertDownload('crm-backup-'.$id.'-database.sql.gz');
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertSame('gzip dump bytes', $response->baseResponse->getFile()->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->actingAs($superAdmin)
            ->get(route('backups.download', ['backup' => $id, 'file' => 'files']))
            ->assertOk()
            ->assertDownload('crm-backup-'.$id.'-files.tar.gz');

        $logs = ActivityLog::query()->where('description', ActivityLogEvent::BackupDownloaded->value)->orderBy('id')->get();

        $this->assertSame(['crm-backup-'.$id.'-database.sql.gz', 'crm-backup-'.$id.'-files.tar.gz'], $logs->map(fn (ActivityLog $log): mixed => $log->properties->get('subject_label'))->all());
        $this->assertSame([$superAdmin->getKey(), $superAdmin->getKey()], $logs->map(fn (ActivityLog $log): int => (int) $log->causer_id)->all());
    }

    #[Test]
    public function a_guest_is_sent_to_the_login_and_nothing_outside_the_listed_sets_can_be_reached(): void
    {
        $id = $this->makeBackupSet($this->backups, now()->subDay());

        $this->get(route('backups.download', ['backup' => $id, 'file' => 'database']))->assertRedirect(route('filament.admin.auth.login'));

        $superAdmin = $this->superAdmin();
        mkdir($this->backups.DIRECTORY_SEPARATOR.'20260101-000000');
        file_put_contents($this->backups.DIRECTORY_SEPARATOR.'20260101-000000'.DIRECTORY_SEPARATOR.'database.sql.gz', 'incomplete set');

        foreach ([
            '/backups/20250101-000000/database',       // no such set
            '/backups/20260101-000000/database',       // incomplete set
            '/backups/'.$id.'/last-failure',           // not a part
            '/backups/'.$id.'/..%2F..%2F.env',         // traversal in the part
            '/backups/..%2F..%2F.env/database',        // traversal in the set
            '/backups/'.$id.'%2F..%2F..%2F.env/files', // traversal after a valid id
        ] as $url) {
            $this->actingAs($superAdmin)->get($url)->assertNotFound();
        }

        $this->assertSame(0, ActivityLog::query()->where('description', ActivityLogEvent::BackupDownloaded->value)->count());
    }

    #[Test]
    public function the_failure_notice_reads_in_both_locales_links_to_the_page_and_mails_by_default(): void
    {
        config()->set('mail.default', 'smtp');
        $superAdmin = $this->superAdmin();
        $failure = new BackupFailure(now()->toImmutable(), BackupException::REASON_FILES, 'tar exited with code 2');
        $notification = new BackupFailedNotification($failure);

        $this->assertSame(['database', 'mail'], $notification->via($superAdmin));
        $this->assertSame(['database', 'mail'], NotificationChannels::for($superAdmin, NotificationEvent::BackupFailed));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            $database = $notification->toDatabase($superAdmin);
            $mail = $notification->toMail($superAdmin);
            $body = __('backups.notifications.failed_body', ['time' => '2026-10-02 09:00', 'reason' => __('backups.reasons.files')]);

            $this->assertSame(__('backups.notifications.failed_title'), $database['title']);
            $this->assertSame($body, $database['body']);
            $this->assertSame(Backups::getUrl(), $database['actions'][0]['url']);
            $this->assertSame(__('backups.notifications.failed_title'), $mail->subject);
            $this->assertContains($body, $mail->introLines);
            $this->assertStringNotContainsString('tar exited', (string) json_encode($database), 'the technical detail stays on the page');
        }
    }
}
