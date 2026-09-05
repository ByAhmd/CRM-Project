<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The six roles seeded by default (decision A-12).
 *
 * Roles live in spatie's `roles` table and super admins may add more at runtime
 * (D-3); this enum names only the seeded set so code can refer to them safely.
 * Labels for these come from lang/{locale}/enums.php; custom roles carry their own
 * bilingual names on the row.
 */
enum CrmRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case SalesManager = 'sales_manager';
    case SalesRep = 'sales_rep';
    case Support = 'support';
    case ReadOnly = 'read_only';

    public function label(): string
    {
        return __('enums.roles.'.$this->value);
    }

    /**
     * The super_admin role may neither be renamed, deleted nor stripped of
     * permissions: the seeder re-grants everything on every run.
     */
    public function isLocked(): bool
    {
        return $this === self::SuperAdmin;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
