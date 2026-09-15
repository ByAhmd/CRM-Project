<?php

declare(strict_types=1);

namespace App\Exceptions\CustomFields;

use RuntimeException;

/**
 * Raised when a custom field definition would break an invariant of the
 * engine (decision D-9): a key that is not a slug, collides with a real
 * column of the entity or with another definition; options on a type that has
 * none (or none on a type that needs them); a validation constraint the type
 * cannot express or a pattern PCRE cannot compile; a change to an immutable
 * attribute; a removal that would take stored values with it.
 *
 * The message is already translated, so the Filament pages show it to the
 * administrator as it is and halt the save.
 */
final class InvalidCustomFieldException extends RuntimeException
{
    public static function keyFormat(): self
    {
        return new self((string) __('custom_fields.validation.key_format'));
    }

    public static function entityUnknown(): self
    {
        return new self((string) __('custom_fields.validation.entity_unknown'));
    }

    public static function typeUnknown(): self
    {
        return new self((string) __('custom_fields.validation.type_unknown'));
    }

    /** A `regex` constraint that PCRE cannot compile, or that is longer than the bound. */
    public static function invalidPattern(string $pattern): self
    {
        return new self((string) __('custom_fields.validation.invalid_pattern', ['pattern' => $pattern]));
    }

    public static function keyReserved(string $key): self
    {
        return new self((string) __('custom_fields.validation.key_reserved', ['key' => $key]));
    }

    public static function keyTaken(): self
    {
        return new self((string) __('custom_fields.validation.key_taken'));
    }

    public static function keyLocked(): self
    {
        return new self((string) __('custom_fields.validation.key_locked'));
    }

    public static function entityLocked(): self
    {
        return new self((string) __('custom_fields.validation.entity_locked'));
    }

    public static function typeLocked(): self
    {
        return new self((string) __('custom_fields.validation.type_locked'));
    }

    public static function optionsRequired(): self
    {
        return new self((string) __('custom_fields.validation.options_required'));
    }

    public static function optionsForbidden(): self
    {
        return new self((string) __('custom_fields.validation.options_forbidden'));
    }

    public static function invalidOption(string $value): self
    {
        return new self((string) __('custom_fields.validation.invalid_option', ['value' => $value]));
    }

    public static function validationNotSupported(string $key): self
    {
        return new self((string) __('custom_fields.validation.validation_not_supported', ['key' => $key]));
    }

    public static function deleteHasValues(int $count): self
    {
        return new self(trans_choice('custom_fields.validation.delete_has_values', $count, ['count' => (string) $count]));
    }

    public static function unsupportedEntity(string $model): self
    {
        return new self((string) __('custom_fields.validation.unsupported_entity', ['model' => $model]));
    }
}
