<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Enums\CrmRole;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OnboardCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_seeds_reference_data_and_creates_the_first_active_super_admin(): void
    {
        $this->assertSame(0, Role::query()->count());

        $this->artisan('app:onboard', [
            '--name' => 'Ahmed',
            '--email' => 'Admin@Example.com',
            '--password' => 'Correct-Horse-Battery-2026',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue(Hash::check('Correct-Horse-Battery-2026', (string) $user->password));
        $this->assertSame(count(CrmRole::cases()), Role::query()->count());
        $this->assertDatabaseHas('settings', ['key' => 'general.currency']);
    }

    #[Test]
    public function it_refuses_a_weak_password_and_a_second_super_admin(): void
    {
        $this->artisan('app:onboard', ['--name' => 'Ahmed', '--email' => 'a@example.com', '--password' => 'weak'])
            ->assertFailed();

        $this->assertSame(0, User::query()->count());

        $this->artisan('app:onboard', ['--name' => 'Ahmed', '--email' => 'a@example.com', '--password' => 'Correct-Horse-Battery-2026'])
            ->assertSuccessful();

        $this->artisan('app:onboard', ['--name' => 'Other', '--email' => 'b@example.com', '--password' => 'Correct-Horse-Battery-2026'])
            ->expectsOutputToContain('already exists')
            ->assertFailed();

        $this->assertSame(1, User::query()->count());
    }
}
