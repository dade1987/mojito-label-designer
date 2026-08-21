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
        if (!is_numeric($degrees)) {
            return 'N';
        }

        $angle = ((int) $degrees) % 360;

        return self::ORIENTATIONS[$angle] ?? 'N';
    }

    /**
     * @param  array<string, mixed>  $element
     */
    public static function forElement(array $element): string
    {
        return self::toZpl($element['rotation'] ?? 0);
    }
}
