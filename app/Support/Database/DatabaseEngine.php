<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;

/**
 * Which server the connection talks to (decision D-1).
 *
 * Production runs on Hostinger shared hosting and the engine — MySQL 8 or
 * MariaDB — is confirmed only before the first production migration, so the
 * few statements the two spell differently branch here. MariaDB answers the
 * mysql driver with a server version that names it, and the mariadb driver's
 * connection reports it unconditionally; both are read through the
 * framework's own detection rather than a second query.
 */
final class DatabaseEngine
{
    public static function isMariaDb(?string $connection = null): bool
    {
        $database = DB::connection($connection);

        return $database instanceof MySqlConnection && $database->isMaria();
    }
}
