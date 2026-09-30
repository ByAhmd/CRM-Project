<?php

declare(strict_types=1);

namespace App\Support\System;

/**
 * Directory containment for the guards that keep private files away from the
 * web root (app:preflight, the backup location of D-16).
 *
 * Both sides are resolved segment by segment: every leading part that exists
 * goes through realpath() (so a symlinked parent resolves to its target), a
 * `..` climbs from what was resolved so far, and only the trailing parts that
 * do not exist yet are kept as written. A directory that is about to be
 * created is therefore judged where it will really land — a `..` segment or a
 * symlinked parent cannot smuggle it into (or out of) the web root before it
 * exists. Separators and (on Windows) case are normalised before comparing.
 */
final class Paths
{
    /** Whether $path is $directory itself or lies anywhere beneath it. */
    public static function isInside(string $path, string $directory): bool
    {
        $path = self::normalise($path);
        $directory = self::normalise($directory);

        return $path !== '' && $directory !== '' && ($path === $directory || str_starts_with($path.'/', $directory.'/'));
    }

    /** Whether the path is absolute on this platform (`/…`, `C:\…`, `\\server\…`). */
    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    /**
     * The path as it resolves on disk, with forward slashes and without a
     * trailing slash; '' for an empty path. A relative path is taken from the
     * working directory, as realpath() would.
     */
    public static function resolve(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        if (! self::isAbsolute($path)) {
            $cwd = getcwd();
            $path = ($cwd === false ? '' : $cwd).'/'.$path;
        }

        [$root, $segments] = self::split($path);
        $resolved = [];
        // How many trailing entries of $resolved do not exist on disk.
        $missing = 0;

        foreach ($segments as $segment) {
            if ($segment === '..') {
                array_pop($resolved);
                $missing = max(0, $missing - 1);

                continue;
            }

            $resolved[] = $segment;

            if ($missing > 0) {
                $missing++;

                continue;
            }

            $real = realpath($root.'/'.implode('/', $resolved));

            if ($real === false) {
                $missing = 1;

                continue;
            }

            [$root, $resolved] = self::split($real);
        }

        return rtrim($root.'/'.implode('/', $resolved), '/');
    }

    private static function normalise(string $value): string
    {
        $value = self::resolve($value);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($value) : $value;
    }

    /**
     * An absolute path cut into its root — '' for `/`, `C:` for a drive,
     * `/` for a UNC `\\server` prefix, so that root.'/'.segments rebuilds it —
     * and its segments without empty and `.` entries.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function split(string $path): array
    {
        $path = str_replace('\\', '/', $path);

        if (preg_match('#^([A-Za-z]:)(/|$)#', $path, $match) === 1) {
            $root = $match[1];
            $rest = substr($path, strlen($match[1]));
        } elseif (str_starts_with($path, '//')) {
            $root = '/';
            $rest = substr($path, 2);
        } else {
            $root = '';
            $rest = $path;
        }

        $segments = array_values(array_filter(
            explode('/', $rest),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.',
        ));

        return [$root, $segments];
    }
}
