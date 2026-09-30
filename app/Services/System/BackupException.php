<?php

declare(strict_types=1);

namespace App\Services\System;

use RuntimeException;
use Throwable;

/**
 * A backup run that did not produce a complete set (decision D-16).
 *
 * `reason` is the broad cause people read (`backups.reasons.*`); `detail` and
 * `parameters` name the exact sentence under `backups.details.*`, rendered in
 * the reader's locale on System → Backups. The exception message is that
 * sentence in English, for the log and the `crm:backup` output. A parameter
 * may carry a tool's error output, which is shown as the tool wrote it.
 * Nothing ever carries a credential: mysqldump receives them through a
 * temporary defaults file, not its command line.
 */
final class BackupException extends RuntimeException
{
    /** The backup directory is unsafe (under public/ or the archived disk), cannot be created or is not writable. */
    public const string REASON_LOCATION = 'location';

    /** mysqldump could not be run, failed, or its output could not be compressed. */
    public const string REASON_DATABASE = 'database';

    /** The private storage disk could not be archived. */
    public const string REASON_FILES = 'files';

    /** A step reported success but left a missing or empty file behind. */
    public const string REASON_INCOMPLETE = 'incomplete';

    /** Anything else that stopped the run. */
    public const string REASON_UNEXPECTED = 'unexpected';

    public const array REASONS = [
        self::REASON_LOCATION,
        self::REASON_DATABASE,
        self::REASON_FILES,
        self::REASON_INCOMPLETE,
        self::REASON_UNEXPECTED,
    ];

    /**
     * @param  string  $detail  a key under `backups.details`
     * @param  array<string, string>  $parameters
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $detail,
        public readonly array $parameters = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(self::sentence($detail, $parameters, 'en'), 0, $previous);
    }

    /**
     * @param  array<string, string>  $parameters
     */
    public static function location(string $detail, array $parameters = []): self
    {
        return new self(self::REASON_LOCATION, $detail, $parameters);
    }

    /**
     * @param  array<string, string>  $parameters
     */
    public static function database(string $detail, array $parameters = [], ?Throwable $previous = null): self
    {
        return new self(self::REASON_DATABASE, $detail, $parameters, $previous);
    }

    /**
     * @param  array<string, string>  $parameters
     */
    public static function files(string $detail, array $parameters = [], ?Throwable $previous = null): self
    {
        return new self(self::REASON_FILES, $detail, $parameters, $previous);
    }

    /**
     * @param  array<string, string>  $parameters
     */
    public static function incomplete(string $detail, array $parameters = []): self
    {
        return new self(self::REASON_INCOMPLETE, $detail, $parameters);
    }

    public static function unexpected(Throwable $previous): self
    {
        return new self(self::REASON_UNEXPECTED, 'unexpected', [
            'exception' => $previous::class,
            'message' => $previous->getMessage(),
        ], $previous);
    }

    /**
     * The detail sentence in the given locale (the current one when null).
     *
     * @param  array<string, string>  $parameters
     */
    public static function sentence(string $detail, array $parameters, ?string $locale = null): string
    {
        return (string) __('backups.details.'.$detail, $parameters, $locale);
    }
}
