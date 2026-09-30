<?php

declare(strict_types=1);

namespace App\Services\System;

use Carbon\CarbonImmutable;

/**
 * How many ERROR-or-higher entries the application log holds for a span of
 * time (decision D-18: the system health part of the weekly summary).
 *
 * Reads the files Laravel's own channels write — the `single` file
 * (logging.channels.single.path, `laravel.log`) and the `daily` files named
 * after their date (`laravel-2026-09-30.log`, from
 * logging.channels.daily.path) whose date falls in the span — and counts the
 * lines that open an entry (`[2026-09-30 22:00:00] production.ERROR: …`)
 * with a timestamp inside the span. Timestamps are compared in the
 * application timezone, the one Monolog writes them in.
 *
 * Defensive by design: a missing or unreadable file counts nothing, lines
 * are read in bounded chunks, and no more than MAX_BYTES are scanned in all —
 * newest first, the tail of an oversized file — in which case the count is
 * reported as incomplete (a lower bound). Only the number leaves this class;
 * no log content is ever returned.
 */
final class LoggedErrorCounter
{
    /** The most bytes one count reads across all files. */
    public const int MAX_BYTES = 16 * 1024 * 1024;

    /** The longest piece of a line read at once; the rest of a longer line is skipped. */
    private const int CHUNK_BYTES = 8192;

    private const string ENTRY = '/^\[(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})[^\]]*\]\s+[^\s:]+\.(ERROR|CRITICAL|ALERT|EMERGENCY):/';

    /**
     * @return array{errors: int, complete: bool}
     */
    public function count(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $timezone = (string) config('app.timezone');
        $start = $from->setTimezone($timezone)->format('Y-m-d H:i:s');
        $end = $to->setTimezone($timezone)->format('Y-m-d H:i:s');

        $budget = self::MAX_BYTES;
        $errors = 0;
        $complete = true;

        foreach ($this->files($from->setTimezone($timezone), $to->setTimezone($timezone)) as $file) {
            if ($budget <= 0) {
                $complete = false;

                break;
            }

            [$found, $read, $whole] = $this->scan($file, $start, $end, $budget);

            $errors += $found;
            $budget -= $read;
            $complete = $complete && $whole;
        }

        return ['errors' => $errors, 'complete' => $complete];
    }

    /**
     * The existing log files that may hold entries of the span, newest first:
     * the daily files of each date from the last day back to the first, then
     * the single file.
     *
     * @return list<string>
     */
    private function files(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $files = [];
        $daily = (string) config('logging.channels.daily.path');

        if ($daily !== '') {
            $info = pathinfo($daily);
            $directory = $info['dirname'];
            $extension = isset($info['extension']) ? '.'.$info['extension'] : '';

            for ($day = $to->startOfDay(); $day->greaterThanOrEqualTo($from->startOfDay()); $day = $day->subDay()) {
                $files[] = $directory.DIRECTORY_SEPARATOR.$info['filename'].'-'.$day->format('Y-m-d').$extension;
            }
        }

        $single = (string) config('logging.channels.single.path');

        if ($single !== '') {
            $files[] = $single;
        }

        return array_values(array_filter(
            array_unique($files),
            static fn (string $file): bool => is_file($file) && is_readable($file),
        ));
    }

    /**
     * Counts the error entries of one file inside the span, reading at most
     * $budget bytes from its end.
     *
     * @return array{0: int, 1: int, 2: bool} errors found, bytes read, whether the whole file was read
     */
    private function scan(string $file, string $start, string $end, int $budget): array
    {
        $size = @filesize($file);
        $handle = @fopen($file, 'rb');

        if ($size === false || $handle === false) {
            return [0, 0, true];
        }

        $whole = $size <= $budget;
        $errors = 0;
        $read = 0;

        try {
            $atLineStart = true;

            if (! $whole) {
                fseek($handle, $size - $budget);
                // The first line read from the middle of the file starts mid-entry: skip it.
                $skipped = (string) fgets($handle, self::CHUNK_BYTES);
                $read += strlen($skipped);
                $atLineStart = str_ends_with($skipped, "\n");
            }

            while ($read < $budget && ($chunk = fgets($handle, self::CHUNK_BYTES)) !== false) {
                $read += strlen($chunk);

                if ($atLineStart && preg_match(self::ENTRY, $chunk, $match) === 1) {
                    $moment = $match[1].' '.$match[2];

                    if ($moment >= $start && $moment <= $end) {
                        $errors++;
                    }
                }

                $atLineStart = str_ends_with($chunk, "\n");
            }
        } finally {
            fclose($handle);
        }

        return [$errors, $read, $whole];
    }
}
