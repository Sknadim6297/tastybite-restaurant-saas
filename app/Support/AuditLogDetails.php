<?php

namespace App\Support;

use Illuminate\Support\Str;
use JsonException;

class AuditLogDetails
{
    private const SENSITIVE_KEY_PATTERN = '/password|token|secret|credential|api.?key|proof.?path|remember|account.?fingerprint|authorization|cookie|session|private.?key/i';

    /**
     * @return list<array{label: string, value: string}>
     */
    public static function format(mixed $details): array
    {
        if (is_string($details)) {
            $raw = trim($details);
            if ($raw === '') {
                return [];
            }

            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $details = is_array($decoded) ? $decoded : ['details' => $decoded];
            } catch (JsonException) {
                if (str_starts_with($raw, '{') || str_starts_with($raw, '[')) {
                    return [];
                }

                return [['label' => 'Details', 'value' => $raw]];
            }
        }

        if (is_object($details)) {
            $details = (array) $details;
        }

        if (! is_array($details) || $details === []) {
            return [];
        }

        $formatted = [];
        self::flatten($details, $formatted);

        return $formatted;
    }

    /**
     * @param  array<mixed>  $details
     * @param  list<array{label: string, value: string}>  $formatted
     */
    private static function flatten(array $details, array &$formatted, string $parentLabel = '', int $depth = 0): void
    {
        if ($depth >= 8) {
            return;
        }

        foreach ($details as $key => $value) {
            $key = (string) $key;
            if (preg_match(self::SENSITIVE_KEY_PATTERN, $key)) {
                continue;
            }

            $label = self::label($key);
            $label = $parentLabel === '' ? $label : $parentLabel.' · '.$label;

            if (is_object($value)) {
                $value = (array) $value;
            }

            if (is_array($value)) {
                if (array_is_list($value)) {
                    $items = array_values(array_filter(
                        array_map(self::scalarValue(...), $value),
                        static fn (?string $item): bool => $item !== null && $item !== '',
                    ));
                    if ($items !== []) {
                        $formatted[] = ['label' => $label, 'value' => implode(', ', $items)];
                    }

                    continue;
                }

                self::flatten($value, $formatted, $label, $depth + 1);

                continue;
            }

            $scalar = self::scalarValue($value);
            if ($scalar !== null && $scalar !== '') {
                $formatted[] = ['label' => $label, 'value' => $scalar];
            }
        }
    }

    private static function label(string $key): string
    {
        return match (strtolower($key)) {
            'restaurant_name' => 'Restaurant',
            'owner_email' => 'Owner Email',
            default => Str::headline(str_replace(['_', '-'], ' ', $key)),
        };
    }

    private static function scalarValue(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'Yes' : 'No',
            is_scalar($value) => (string) $value,
            default => null,
        };
    }
}
