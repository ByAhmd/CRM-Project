<?php

declare(strict_types=1);

namespace App\Support\System;

/**
 * The PHP platform the CRM needs (decision D-1: PHP 8.3 is the language
 * floor on the shared host) and what the running PHP provides.
 *
 * REQUIRED_EXTENSIONS mirrors the `ext-*` entries of composer.json's
 * `require` (a test keeps the two equal): intl for number and currency
 * formatting, fileinfo for sniffing attachments and imports, mbstring for
 * Arabic text, pdo_mysql for the database, openssl for encryption and
 * sessions, and dom, xmlreader and zip for XLSX import and export (OpenSpout)
 * and for recognising Office uploads.
 *
 * app:preflight reads it through the container; the constructor takes the
 * version and the loaded extensions so a test can describe another platform.
 */
final readonly class PlatformRequirements
{
    public const MINIMUM_PHP = '8.3.0';

    /** @var list<string> */
    public const REQUIRED_EXTENSIONS = ['dom', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'xmlreader', 'zip'];

    private string $phpVersion;

    /** @var list<string> lower-case extension names */
    private array $loadedExtensions;

    /**
     * @param  ?list<string>  $loadedExtensions
     */
    public function __construct(?string $phpVersion = null, ?array $loadedExtensions = null)
    {
        $this->phpVersion = $phpVersion ?? PHP_VERSION;
        $this->loadedExtensions = array_map(strtolower(...), $loadedExtensions ?? get_loaded_extensions());
    }

    public function phpVersion(): string
    {
        return $this->phpVersion;
    }

    public function phpIsSupported(): bool
    {
        return version_compare($this->phpVersion, self::MINIMUM_PHP, '>=');
    }

    /**
     * The required extensions the running PHP does not load.
     *
     * @return list<string>
     */
    public function missingExtensions(): array
    {
        return array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            fn (string $extension): bool => ! in_array($extension, $this->loadedExtensions, true),
        ));
    }
}
