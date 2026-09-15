<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * Minimal, real file contents for upload tests: each builder returns bytes
 * that finfo sniffs as the named type, so the attachment MIME allowlist
 * (A-6) is exercised against content rather than against a client-supplied
 * extension.
 */
trait BuildsUploadBytes
{
    /** A well-formed PDF with an empty page tree (application/pdf). */
    protected function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /** A 1x1 transparent PNG (image/png). */
    protected function pngBytes(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
    }

    /** The header of a Windows PE executable (application/x-dosexec), which the allowlist refuses. */
    protected function executableBytes(): string
    {
        return "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00".str_repeat("\x00", 100).'This program cannot be run in DOS mode.';
    }
}
