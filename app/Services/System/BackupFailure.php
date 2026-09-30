<?php

declare(strict_types=1);

namespace App\Services\System;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * The most recent backup run that failed (decision D-16), as BackupService
 * records it next to the sets until the next run succeeds.
 *
 * `reason` is one of the BackupException::REASON_* codes, translated for
 * people under `backups.reasons.*`; `detail` is the exact explanation in
 * English (the log, app:preflight); `detailKey` and `parameters` let
 * System → Backups show that explanation in the reader's locale (a tool's
 * error output inside it stays as the tool wrote it). Never a credential.
 */
final readonly class BackupFailure
{
    /**
     * @param  array<string, string>  $parameters
     */
    public function __construct(
        public CarbonImmutable $occurredAt,
        public string $reason,
        public string $detail,
        public ?string $detailKey = null,
        public array $parameters = [],
    ) {}

    public static function fromException(BackupException $exception, CarbonImmutable $occurredAt): self
    {
        return new self($occurredAt, $exception->reason, $exception->getMessage(), $exception->detail, $exception->parameters);
    }

    /** The explanation in the current locale; the recorded English text when no translation is known. */
    public function localisedDetail(): string
    {
        if ($this->detailKey === null || ! Lang::has('backups.details.'.$this->detailKey)) {
            return $this->detail;
        }

        return BackupException::sentence($this->detailKey, $this->parameters);
    }

    /**
     * @return array{occurred_at: string, reason: string, detail: string, detail_key: string|null, parameters: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'reason' => $this->reason,
            'detail' => $this->detail,
            'detail_key' => $this->detailKey,
            'parameters' => $this->parameters,
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $occurredAt = $data['occurred_at'] ?? null;
        $reason = $data['reason'] ?? null;
        $detail = $data['detail'] ?? null;
        $detailKey = $data['detail_key'] ?? null;
        $parameters = $data['parameters'] ?? [];

        if (! is_string($occurredAt) || ! is_string($reason) || ! is_string($detail) || ! in_array($reason, BackupException::REASONS, true)) {
            return null;
        }

        try {
            $moment = CarbonImmutable::make($occurredAt);
        } catch (Throwable) {
            return null;
        }

        $clean = [];

        if (is_array($parameters)) {
            foreach ($parameters as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $clean[$key] = $value;
                }
            }
        }

        return $moment === null ? null : new self($moment, $reason, $detail, is_string($detailKey) ? $detailKey : null, $clean);
    }
}
