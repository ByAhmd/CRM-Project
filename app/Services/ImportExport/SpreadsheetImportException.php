<?php

declare(strict_types=1);

namespace App\Services\ImportExport;

use RuntimeException;
use Throwable;

/**
 * Why an uploaded workbook could not be turned into the CSV the import
 * pipeline reads (module 18).
 *
 * The reason is a machine token, never a sentence: a service carries no
 * user-facing string (CLAUDE.md section 3), so the Filament layer maps the
 * token to the translated message the importer shows.
 */
final class SpreadsheetImportException extends RuntimeException
{
    /** The upload is not one of the workbook formats OpenSpout reads. */
    public const UNSUPPORTED_FORMAT = 'unsupported_format';

    /** The file is not a workbook at all, or its container is damaged. */
    public const UNREADABLE = 'unreadable';

    /** The first worksheet carries no row, so there is no header to map columns against. */
    public const EMPTY_SHEET = 'empty_sheet';

    /** The first worksheet carries more data rows than the import action allows. */
    public const TOO_MANY_ROWS = 'too_many_rows';

    private function __construct(
        private readonly string $reason,
        private readonly ?int $rowLimit = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }

    public static function unsupportedFormat(): self
    {
        return new self(self::UNSUPPORTED_FORMAT);
    }

    public static function unreadable(?Throwable $previous = null): self
    {
        return new self(self::UNREADABLE, previous: $previous);
    }

    public static function emptySheet(): self
    {
        return new self(self::EMPTY_SHEET);
    }

    public static function tooManyRows(int $limit): self
    {
        return new self(self::TOO_MANY_ROWS, $limit);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** The row ceiling that was exceeded, so the message can name it. */
    public function rowLimit(): ?int
    {
        return $this->rowLimit;
    }
}
