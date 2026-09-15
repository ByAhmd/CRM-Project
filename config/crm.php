<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CRM application defaults
|--------------------------------------------------------------------------
|
| Deployment-level defaults. Values an administrator may change at runtime
| (currency, timezone, week start) are seeded from here into the settings
| table and read through App\Services\Settings\SettingsRepository afterwards.
| Everything else is fixed per deployment and documented in docs/DECISIONS.md.
|
*/

return [

    // D-8: single currency, default SAR; overridable in General Settings.
    'currency' => env('CRM_CURRENCY', 'SAR'),

    // D-8: organisation timezone; overridable in General Settings.
    'timezone' => env('CRM_TIMEZONE', 'Asia/Riyadh'),

    // 0 = Sunday … 6 = Saturday. Saudi working week starts on Sunday.
    'week_starts_on' => 0,

    'leads' => [
        // Days without activity after which an open lead is reported stale to its owner.
        'stale_days' => (int) env('CRM_LEAD_STALE_DAYS', 14),
    ],

    'audit' => [
        // D-13: how long audit rows are kept before the weekly prune.
        'retention_days' => (int) env('CRM_AUDIT_RETENTION_DAYS', 730),
    ],

    // Reverse proxies whose X-Forwarded-* headers are believed (App\Http\Middleware\TrustProxies).
    // Unset = trust nobody (secure default); '*' = trust any peer (only when PHP is reachable solely
    // through the host's proxy — otherwise clients can spoof their IP and https); or a comma-separated
    // list of IPs / CIDR ranges, preferred whenever the host publishes its proxy addresses.
    'trusted_proxies' => env('CRM_TRUSTED_PROXIES'),

    'invitations' => [
        // Minutes an invitation / password-reset link stays valid (Laravel broker default).
        'expire_minutes' => 60,
    ],

    'attachments' => [
        'disk' => 'local',
        'max_kb' => (int) env('CRM_ATTACHMENT_MAX_KB', 10240),
        'allowed_mime_types' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv',
            'text/plain',
            'image/png',
            'image/jpeg',
            'image/webp',
        ],
    ],

];
