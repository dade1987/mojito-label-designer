<?php

declare(strict_types=1);

namespace Mojito\Label;

/**
 * Trova il file TrueType con cui disegnare il testo delle etichette.
 *
 * La stampa grafica non ha i font residenti della stampante: il testo lo
 * disegna GD, quindi serve un .ttf vero. Ne portiamo uno dietro (Liberation
 * Sans Narrow, stretto come il Roboto Condensed del designer) così il
 * risultato è identico su ogni macchina; le alternative di sistema restano
 * come rete di sicurezza per installazioni che il file bundle non ce l'hanno.
 */
final class LabelFont
{
    /**
     * Il rapporto fra l'altezza del carattere in dot (come la intende ZPL, che
     * è l'altezza della cella) e la "size" che vuole imagettftext.
     */
    public const POINT_RATIO = 0.75;

    public static function regular(): ?string
    {
        return self::firstExisting([
            self::fromEnvironment('MOJITO_LABEL_FONT'),
            dirname(__DIR__).'/resources/fonts/LiberationSansNarrow-Regular.ttf',
            __DIR__.'/../resources/fonts/LiberationSansNarrow-Regular.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSansNarrow-Regular.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSansCondensed.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            'C:\\Windows\\Fonts\\arialn.ttf',
            'C:\\Windows\\Fonts\\arial.ttf',
        ]);
    }

    public static function bold(): ?string
    {
        return self::firstExisting([
            self::fromEnvironment('MOJITO_LABEL_FONT_BOLD'),
            dirname(__DIR__).'/resources/fonts/LiberationSansNarrow-Bold.ttf',
            __DIR__.'/../resources/fonts/LiberationSansNarrow-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSansNarrow-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSansCondensed-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            'C:\\Windows\\Fonts\\arialnb.ttf',
            'C:\\Windows\\Fonts\\arialbd.ttf',
        ]) ?? self::regular();
    }

    private static function fromEnvironment(string $variable): string
    {
        $value = getenv($variable);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param  list<string>  $candidates
     */
    private static function firstExisting(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
