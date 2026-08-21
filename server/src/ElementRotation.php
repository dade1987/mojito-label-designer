<?php

declare(strict_types=1);

namespace Mojito\Label;

/**
 * L'orientamento di un elemento, nei termini che lo ZPL capisce.
 *
 * Lo ZPL non ha una rotazione libera: ogni comando accetta una lettera fra
 * quattro orientamenti fissi. Far scegliere un angolo qualsiasi
 * prometterebbe qualcosa che la stampante non sa fare, quindi tutto quello
 * che non e' uno dei quattro torna dritto.
 */
final class ElementRotation
{
    /** @var array<int, string> */
    private const ORIENTATIONS = [
        0 => 'N',   // normale
        90 => 'R',  // ruotato di 90 gradi
        180 => 'I', // capovolto
        270 => 'B', // ruotato all'indietro
    ];

    public static function toZpl(mixed $degrees): string
    {
        return self::ORIENTATIONS[self::degrees($degrees)] ?? 'N';
    }

    /**
     * L'angolo che verra' stampato davvero: 0, 90, 180 o 270.
     */
    public static function degrees(mixed $degrees): int
    {
        if (! is_numeric($degrees)) {
            return 0;
        }

        $angle = ((int) $degrees) % 360;

        return array_key_exists($angle, self::ORIENTATIONS) ? $angle : 0;
    }

    /**
     * @param  array<string, mixed>  $element
     */
    public static function degreesForElement(array $element): int
    {
        return self::degrees($element['rotation'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $element
     */
    public static function forElement(array $element): string
    {
        return self::toZpl($element['rotation'] ?? 0);
    }
}
