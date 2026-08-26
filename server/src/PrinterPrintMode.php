<?php

declare(strict_types=1);

namespace Mojito\Label;

/**
 * Come va stampato su una stampante, quando si puo' sapere dal modello.
 *
 * Una Citizen o una Zebra parlano ZPL e vogliono i comandi in RAW; una Munbyn
 * lo ZPL non lo capisce e va servita con l'etichetta gia' disegnata, tramite
 * il driver di sistema. Sbagliare strada non da' errori: esce carta bianca o
 * testo a caso. Il nome della stampante quasi sempre contiene il modello, e
 * il modello dice quale delle due strade e' quella giusta.
 *
 * Dove il nome non basta, l'installazione lo puo' dichiarare con
 * MOJITO_PRINTER_MODE="Nome=graphic,Altra=zpl". Come per la risoluzione, la
 * dichiarazione vince sul riconoscimento automatico.
 */
final class PrinterPrintMode
{
    /**
     * Modelli riconosciuti dal nome: la chiave e' cercata nel nome
     * normalizzato, quindi funziona con qualunque punteggiatura.
     *
     * @var array<string, string>
     */
    private const KNOWN_MODELS = [
        // Munbyn ITPP941P: stampante termica da ufficio, solo driver di sistema.
        'munbyn' => LabelPrinterService::MODE_GRAPHIC,
        'itpp' => LabelPrinterService::MODE_GRAPHIC,
        // Le ZPL di reparto.
        'citizen' => LabelPrinterService::MODE_ZPL,
        'cls70' => LabelPrinterService::MODE_ZPL,
        'zebra' => LabelPrinterService::MODE_ZPL,
        'apex' => LabelPrinterService::MODE_ZPL,
        'apix' => LabelPrinterService::MODE_ZPL,
    ];

    public static function forPrinter(string $printer): ?string
    {
        $name = trim($printer);

        if ($name === '') {
            return null;
        }

        foreach (self::declared() as $declaredName => $mode) {
            if (self::normalize($declaredName) === self::normalize($name)) {
                return $mode;
            }
        }

        $normalized = self::normalize($name);
        foreach (self::KNOWN_MODELS as $model => $mode) {
            if (str_contains($normalized, self::normalize($model))) {
                return $mode;
            }
        }

        // Meglio nessun valore che uno inventato: il layout tiene il suo.
        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function declared(): array
    {
        $raw = getenv('MOJITO_PRINTER_MODE');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $map = [];
        foreach (explode(',', $raw) as $pair) {
            $parts = explode('=', $pair, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $mode = strtolower(trim($parts[1]));
            if ($mode === LabelPrinterService::MODE_ZPL || $mode === LabelPrinterService::MODE_GRAPHIC) {
                $map[trim($parts[0])] = $mode;
            }
        }

        return $map;
    }

    private static function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
    }
}
