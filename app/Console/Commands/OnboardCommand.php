<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CrmRole;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\RoleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password as promptPassword;
use function Laravel\Prompts\text;

/**
 * Creates the first super administrator (decisions D-11, A-12).
 *
 * Accounts are invite-only, so somebody has to exist before anybody can be
 * invited. Runs the reference seeders first if they have not been run, then
 * creates an ACTIVE super admin with the given password. Refuses to run once a
 * super admin exists: further users are invited from the panel.
 *
 * Operator-facing CLI output is plain English; user-facing strings are governed
 * by the bilingual rule, deploy logs are read by engineers.
 */
final class OnboardCommand extends Command
{
    protected $signature = 'app:onboard
        {--name= : Administrator full name}
        {--email= : Administrator email}
        {--password= : Administrator password}';

    protected $description = 'Seed reference data and create the first active super administrator';

    public function handle(RoleService $roles): int
    {
        if (Role::query()->where('name', CrmRole::SuperAdmin->value)->doesntExist()) {
            $this->components->info('Seeding roles, permissions and settings…');
            $this->call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
            $this->call('db:seed', ['--class' => SettingsSeeder::class, '--force' => true]);
        }

        if (User::query()->role(CrmRole::SuperAdmin->value)->exists()) {
            $this->components->error('A super administrator already exists. Invite further users from the panel.');

            return self::FAILURE;
        }

        $configuredEmail = $this->configured('admin.email');

        $name = $this->option('name')
            ?? $this->configured('admin.name')
            ?? ($configuredEmail !== null ? Str::before($configuredEmail, '@') : null)
            ?? text(label: 'Administrator name', required: true);

        $email = $this->option('email')
            ?? $configuredEmail
            ?? text(label: 'Administrator email', required: true);

        $password = $this->option('password')
            ?? $this->configured('admin.password')
            ?? promptPassword(label: 'Password (min. 12 characters, mixed case, a number)', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'min:2', 'max:100'],
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', 'string', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($roles, $name, $email, $password): User {
            $user = User::query()->create([
                'name' => $name,
                'email' => Str::lower($email),
                'password' => $password,
                'status' => UserStatus::Active,
                'locale' => config('app.locale'),
            ]);

            $roles->syncUserRoles($user, [CrmRole::SuperAdmin->value]);

            return $user;
        });

        $this->components->info("Super administrator created: {$user->email}");
        $this->components->info('Sign in at /admin');

        return self::SUCCESS;
    }

    private function configured(string $key): ?string
    {
        $value = trim((string) config($key));

        return $value === '' ? null : $value;
    }
}
