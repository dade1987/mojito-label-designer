<?php

declare(strict_types=1);

namespace Mojito\Label;

/**
 * La risoluzione di stampa di una stampante, quando si puo' sapere.
 *
 * Disegnare a 203 punti per pollice un'etichetta che verra' stampata a 300
 * significa mandarla in stampa di misura sbagliata: giusta sullo schermo,
 * storta sulla carta. Il sistema operativo non la dichiara per le stampanti
 * usate in modalita' raw, ma il nome quasi sempre contiene il modello, e il
 * modello la risoluzione la definisce.
 *
 * Dove il nome non basta, l'installazione la puo' dichiarare con
 * MOJITO_PRINTER_DPI="Nome=300,Altra=203". Chi conosce il proprio impianto ha
 * ragione sul riconoscimento automatico, quindi quella dichiarazione vince.
 */
final class PrinterResolution
{
    /**
     * Modelli riconosciuti dal nome. La chiave e' cercata nel nome
     * normalizzato, quindi funziona con qualunque punteggiatura.
     *
     * @var array<string, int>
     */
    private const KNOWN_MODELS = [
        // Nella famiglia Citizen CL-S700 la terza cifra indica la
        // risoluzione: 700 e' 203 dpi, 703 e' 300 dpi.
        'clS703' => 300,
        'clS700' => 203,
        // Apex: le stampanti installate in reparto sono a 600 dpi.
        'apex' => 600,
    ];

    public static function forPrinter(string $printer): ?int
    {
        $name = trim($printer);

        if ($name === '') {
            return null;
        }

        $declared = self::declared();
        foreach ($declared as $declaredName => $dpi) {
            if (self::normalize($declaredName) === self::normalize($name)) {
                return $dpi;
            }
        }

        $normalized = self::normalize($name);
        foreach (self::KNOWN_MODELS as $model => $dpi) {
            if (str_contains($normalized, self::normalize($model))) {
                return $dpi;
            }
        }

        // Meglio nessun valore che uno inventato: chi disegna lo imposta a mano.
        return null;
    }

    /**
     * @return array<string, int>
     */
    private static function declared(): array
    {
        $raw = getenv('MOJITO_PRINTER_DPI');

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $map = [];
        foreach (explode(',', $raw) as $pair) {
            $parts = explode('=', $pair, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $dpi = (int) trim($parts[1]);
            if ($dpi > 0) {
                $map[trim($parts[0])] = $dpi;
            }
        }

        return $map;
    }

    private static function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
    }
}
