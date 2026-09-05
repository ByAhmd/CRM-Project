<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Normalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NormalizerTest extends TestCase
{
    #[Test]
    public function emails_are_trimmed_and_lower_cased(): void
    {
        $this->assertSame('sara@example.com', Normalizer::email('  Sara@Example.COM '));
        $this->assertNull(Normalizer::email(''));
        $this->assertNull(Normalizer::email('   '));
        $this->assertNull(Normalizer::email(null));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function phones(): array
    {
        return [
            'local mobile with leading zero' => ['0551234567', '+966551234567'],
            'local mobile with spaces and dashes' => ['055 123-4567', '+966551234567'],
            'local mobile without zero' => ['551234567', '+966551234567'],
            'national format' => ['966551234567', '+966551234567'],
            'international plus' => ['+966 55 123 4567', '+966551234567'],
            'international double zero' => ['00966551234567', '+966551234567'],
            'foreign number' => ['+971 50 123 4567', '+971501234567'],
            'foreign double zero' => ['0044 20 7946 0958', '+442079460958'],
            'landline with zero' => ['0112345678', '+966112345678'],
            'unparseable digits kept' => ['12345', '+12345'],
            'empty' => ['', null],
            'null' => [null, null],
            'no digits' => ['abc', null],
        ];
    }

    #[Test]
    #[DataProvider('phones')]
    public function phones_are_normalised_to_e164(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, Normalizer::phone($input));
    }
}
