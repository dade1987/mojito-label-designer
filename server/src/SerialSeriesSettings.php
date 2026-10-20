<?php

declare(strict_types=1);

namespace Mojito\Label;

/**
 * Le impostazioni della stampa a serie salvate nel layout: quale campo
 * riceve il codice completo (prefisso + numero + suffisso), quale solo il
 * numero, zeri davanti e passo. Stesse regole del designer
 * (normalizeSeriesSettings in src/utils/serialRange.js).
 */
final class SerialSeriesSettings
{
    public const MAX_PAD = 12;

    /**
     * @return array{codeField: string, numberField: string, prefix: string, suffix: string, pad: int, step: int}
     */
    public static function sanitize(mixed $raw): array
    {
        $source = is_array($raw) ? $raw : [];
        $step = self::wholeNumber($source['step'] ?? null) ?? 1;

        return [
            'codeField' => self::text($source['codeField'] ?? null),
            'numberField' => self::text($source['numberField'] ?? null),
            'prefix' => self::text($source['prefix'] ?? null),
            'suffix' => self::text($source['suffix'] ?? null),
            'pad' => min(self::MAX_PAD, max(0, self::wholeNumber($source['pad'] ?? null) ?? 0)),
            'step' => $step >= 1 ? $step : 1,
        ];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
    }
}
