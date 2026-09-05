<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\ActivityLogEvent;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Security events reach the ledger (A-5) and the ledger is append-only.
 */
final class AuthActivityTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    #[Test]
    public function sign_in_and_sign_out_are_recorded_and_last_login_is_stamped(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->last_login_at);

        Auth::login($user);
        Auth::logout();

        $this->assertNotNull($user->fresh()?->last_login_at);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::AuthLogin->value, 'subject_id' => $user->getKey(), 'causer_id' => $user->getKey()]);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::AuthLogout->value, 'subject_id' => $user->getKey()]);
    }

    #[Test]
    public function a_failed_attempt_is_recorded_without_storing_unknown_addresses(): void
    {
        $user = User::factory()->create();

        Auth::attempt(['email' => $user->email, 'password' => 'wrong-password']);
        Auth::attempt(['email' => 'nobody@example.com', 'password' => 'wrong-password']);

        $known = ActivityLog::query()->where('description', ActivityLogEvent::AuthFailed->value)->where('subject_id', $user->getKey())->firstOrFail();
        $this->assertTrue($known->properties->get('known_account'));

        $unknown = ActivityLog::query()->where('description', ActivityLogEvent::AuthFailed->value)->whereNull('subject_id')->firstOrFail();
        $this->assertFalse($unknown->properties->get('known_account'));
        $this->assertStringNotContainsString('nobody@example.com', (string) json_encode($unknown->properties));
    }

    #[Test]
    public function the_ledger_never_stores_passwords_or_secrets(): void
    {
        $user = User::factory()->create();

        $user->update(['name' => 'Changed', 'password' => 'Another-Secret-Password-1']);
        $user->saveAppAuthenticationSecret('TOTPSECRET123');

        $dump = ActivityLog::query()->get()->map(fn (ActivityLog $log): string => (string) json_encode($log->properties))->implode(' ');

        $this->assertStringNotContainsString('Another-Secret-Password-1', $dump);
        $this->assertStringNotContainsString('TOTPSECRET123', $dump);
        $this->assertStringNotContainsString('password', $dump);
    }

    #[Test]
    public function ledger_rows_can_be_neither_updated_nor_deleted(): void
    {
        $user = User::factory()->create();
        Auth::login($user);

        $row = ActivityLog::query()->latest('id')->firstOrFail();

        try {
            $row->update(['description' => 'tampered']);
            $this->fail('update should have been refused');
        } catch (LogicException) {
            $this->assertSame(ActivityLogEvent::AuthLogin->value, $row->fresh()?->description);
        }

        try {
            $row->delete();
            $this->fail('delete should have been refused');
        } catch (LogicException) {
            $this->assertDatabaseHas('activity_log', ['id' => $row->getKey()]);
        }
    }
}
