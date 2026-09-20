<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use SensitiveParameter;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/**
 * A CRM user (decisions D-3, D-4, D-5, D-11).
 *
 * Roles and permissions come from spatie's tables (HasRoles); the seeded
 * defaults are in RolePermissionMatrix. Visibility over owned records is
 * resolved from those permissions plus team membership by
 * RecordVisibilityResolver — nothing about scope is stored here.
 *
 * Accounts are invite-only: password is nullable until the invitee sets it
 * through the invitation link, and status stays Pending until then.
 *
 * @property UserStatus $status
 * @property ?Carbon $email_verified_at
 * @property ?Carbon $last_login_at
 * @property ?string $app_authentication_secret
 * @property ?array<int, string> $app_authentication_recovery_codes
 * @property bool $has_email_authentication
 */
#[Fillable(['name', 'email', 'phone', 'locale', 'timezone', 'password', 'status', 'team_id'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
final class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasEmailAuthentication, HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use LogsActivity;
    use Notifiable;
    use SoftDeletes;

    /**
     * Audit whitelist: identity, status and team. Passwords, tokens and MFA
     * secrets are outside the whitelist and never reach the ledger.
     *
     * @var list<string>
     */
    protected static array $recordEvents = ['created', 'updated', 'deleted', 'restored'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(ActivityLogEvent::UserUpdated->logName())
            ->logOnly(['name', 'email', 'phone', 'locale', 'status', 'team_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return match ($eventName) {
            'created' => ActivityLogEvent::UserCreated->value,
            'deleted' => ActivityLogEvent::UserDeleted->value,
            'restored' => ActivityLogEvent::UserRestored->value,
            default => ActivityLogEvent::UserUpdated->value,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
            'has_email_authentication' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The channel choices the user has made per notification event (plan
     * section 3.6); events without a row take the enum defaults.
     *
     * @return HasMany<NotificationPreference, $this>
     */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /** Only active accounts may enter the panel; roles decide what they see inside. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status->canAuthenticate();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(CrmRole::SuperAdmin->value);
    }

    /** Notifications and mail are rendered in the user's own language (D-5). */
    public function preferredLocale(): string
    {
        $locale = $this->locale;

        if (is_string($locale) && in_array($locale, (array) config('app.locales'), true)) {
            return $locale;
        }

        return (string) config('app.locale');
    }

    // --- Filament multi-factor authentication (D-11: available, optional) ---

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->forceFill(['app_authentication_secret' => $secret])->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /**
     * @return ?array<int, string>
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /**
     * @param  ?array<int, string>  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->forceFill(['app_authentication_recovery_codes' => $codes])->save();
    }

    public function hasEmailAuthentication(): bool
    {
        return $this->has_email_authentication;
    }

    public function toggleEmailAuthentication(bool $condition): void
    {
        $this->forceFill(['has_email_authentication' => $condition])->save();
    }
}
