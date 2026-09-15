<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

/**
 * The two small demo attachments, generated in memory so the command ships
 * no binary fixtures and needs no image or PDF extension: a one-page PDF and
 * a 16×16 PNG. Both are real files — AttachmentStorage sniffs them with
 * finfo exactly as it sniffs an upload.
 */
final class DemoFiles
{
    /** A one-page A4 PDF with a single line of Helvetica text (ASCII only). */
    public static function pdf(string $line): string
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], preg_replace('/[^\x20-\x7E]/', '', $line) ?? '');
        $stream = "BT /F1 18 Tf 72 760 Td ({$text}) Tj ET";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n".'0000000000 65535 f '."\n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf.'trailer'."\n".'<< /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\n".'startxref'."\n".$xref."\n".'%%EOF'."\n";
    }

    /**
     * A 16×16 RGB PNG: a two-tone diagonal in the CRM primary colour. The
     * image data is written as one uncompressed deflate block, so no zlib
     * call is needed.
     */
    public static function png(): string
    {
        $size = 16;
        $raw = '';

        for ($y = 0; $y < $size; $y++) {
            $raw .= "\x00";

            for ($x = 0; $x < $size; $x++) {
                $raw .= $x >= $y ? "\x1D\x4E\xD8" : "\xE0\xE7\xFF";
            }
        }

        $length = strlen($raw);
        $deflate = "\x78\x01"
            ."\x01".pack('v', $length).pack('v', ~$length & 0xFFFF)
            .$raw
            .(string) hex2bin(hash('adler32', $raw));

        return "\x89PNG\r\n\x1A\n"
            .self::chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 2, 0, 0, 0))
            .self::chunk('IDAT', $deflate)
            .self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
