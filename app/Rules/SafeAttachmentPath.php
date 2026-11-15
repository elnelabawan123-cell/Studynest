<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Ensures uploaded attachment paths stay relative to known storage folders
 * without directory traversal, scheme prefixes, or control characters.
 */
class SafeAttachmentPath implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $trimmed = trim($value);
        if (
            strlen($trimmed) > 255
            || str_contains($trimmed, '..')
            || str_contains($trimmed, '\\')
            || str_contains($trimmed, '://')
            || str_starts_with($trimmed, '/')
            || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-\/]*$/', $trimmed)
        ) {
            $fail("The {$attribute} contains an invalid file path.");
        }
    }
}
