<?php

declare(strict_types=1);

namespace App\Services\System;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * One complete backup set (decision D-16): a folder named after the moment the
 * run started, in the application timezone (`20260930-220000`), holding the
 * gzip'd database dump and the tar.gz of the private disk. Only folders whose
 * two files both exist and are non-empty are sets; BackupService never lists,
 * serves or prunes anything else.
 */
final readonly class BackupSet
{
    public const string DATABASE = 'database';

    public const string FILES = 'files';

    /** File name of each part inside the set folder. */
    public const array FILE_NAMES = [
        self::DATABASE => 'database.sql.gz',
        self::FILES => 'files.tar.gz',
    ];

    public const string ID_FORMAT = 'Ymd-His';

    public function __construct(
        public string $id,
        public CarbonImmutable $createdAt,
        public string $directory,
        public int $databaseBytes,
        public int $filesBytes,
    ) {}

    /** Whether the string is a set folder name: the ID_FORMAT pattern and a real date and time. */
    public static function isId(string $id): bool
    {
        return self::parseId($id) !== null;
    }

    /** The moment a set id names, in the application timezone; null when it names none. */
    public static function parseId(string $id): ?CarbonImmutable
    {
        if (preg_match('/^\d{8}-\d{6}$/', $id) !== 1) {
            return null;
        }

        try {
            $moment = CarbonImmutable::createFromFormat('!'.self::ID_FORMAT, $id, (string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        return $moment instanceof CarbonImmutable && $moment->format(self::ID_FORMAT) === $id ? $moment : null;
    }

    public static function isPart(string $part): bool
    {
        return array_key_exists($part, self::FILE_NAMES);
    }

    public function path(string $part): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.self::FILE_NAMES[$part];
    }

    public function bytes(string $part): int
    {
        return $part === self::DATABASE ? $this->databaseBytes : $this->filesBytes;
    }

    public function totalBytes(): int
    {
        return $this->databaseBytes + $this->filesBytes;
    }

    /** The name a downloaded part is saved under: `crm-backup-20260930-220000-database.sql.gz`. */
    public function downloadName(string $part): string
    {
        return 'crm-backup-'.$this->id.'-'.self::FILE_NAMES[$part];
    }
}
