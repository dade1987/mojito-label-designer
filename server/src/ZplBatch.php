<?php

declare(strict_types=1);

namespace Mojito\Label;

/**
 * Piu' etichette ZPL in un lavoro solo, senza mai cambiare la modalita' della
 * stampante.
 *
 * Niente ^MM (strappo, tear on, ...): la Citizen lo salva, e se una serie si
 * ferma prima della fine resta nella modalita' cambiata. Le copie della
 * stessa etichetta diventano invece un formato solo con ^PQ: la stampante le
 * esegue di fila, senza ritorno della carta fra l'una e l'altra.
 *
 * Le immagini (^GF) vanno caricate una volta sola: la Citizen ^GF non lo
 * supporta davvero, lo emula rielaborando l'immagine a ogni etichetta, e in
 * una serie resta indietro e si ferma. In una serie ogni immagine si carica
 * in memoria con ~DG prima della prima etichetta e ogni etichetta la
 * richiama con ^XG, che la Citizen supporta.
 */
final class ZplBatch
{
    private const GRAPHIC_FIELD = '/\^GFA,\d+,(\d+),(\d+),([0-9A-F]+)/i';

    /** Chi ha gia' una quantita' o numera da se' (con ^PQ avanzerebbe a ogni copia). */
    private const COUNTS_ON_ITS_OWN = '/\^(PQ|SN|SF)/i';

    public static function repeat(string $zpl, int $copies): string
    {
        return self::continuous(str_repeat($zpl, max(1, $copies)));
    }

    public static function continuous(string $zpl): string
    {
        $parts = preg_split('/(\^XA)/i', $zpl, -1, PREG_SPLIT_DELIM_CAPTURE);

        // Un formato solo (o nessuno): non c'e' niente da mettere di fila.
        if ($parts === false || count($parts) < 5) {
            return $zpl;
        }

        /** @var list<array{string, int}> $formats [formato, copie] */
        $formats = [];

        for ($i = 1; $i < count($parts); $i += 2) {
            $format = $parts[$i].$parts[$i + 1];
            $previous = count($formats) - 1;

            if ($previous >= 0 && $formats[$previous][0] === $format && self::takesQuantity($format)) {
                $formats[$previous][1]++;
            } else {
                $formats[] = [$format, 1];
            }
        }

        $result = $parts[0].implode('', array_map(
            static fn (array $format): string => $format[1] > 1 ? self::withQuantity($format[0], $format[1]) : $format[0],
            $formats
        ));

        return count($formats) > 1 ? self::storeGraphics($result) : $result;
    }

    private static function takesQuantity(string $format): bool
    {
        return preg_match(self::COUNTS_ON_ITS_OWN, $format) === 0 && stripos($format, '^XZ') !== false;
    }

    /** ^PQ subito prima dell'ultimo ^XZ del formato. */
    private static function withQuantity(string $format, int $copies): string
    {
        $end = (int) strripos($format, '^XZ');

        return substr($format, 0, $end).'^PQ'.$copies.substr($format, $end);
    }

    /**
     * Ogni ^GFA diventa un richiamo (^XG) a un'immagine caricata una volta
     * sola in testa (~DG). Il nome dipende dal contenuto: la stessa immagine
     * in etichette diverse si carica una volta, e ricaricarla in una serie
     * successiva la sovrascrive invece di riempire la memoria.
     */
    private static function storeGraphics(string $zpl): string
    {
        $downloads = [];

        $recalled = preg_replace_callback(self::GRAPHIC_FIELD, static function (array $match) use (&$downloads): string {
            [, $totalBytes, $bytesPerRow, $data] = $match;
            $data = strtoupper($data);
            $name = sprintf('M%07X', crc32($bytesPerRow.','.$data) & 0xFFFFFFF);
            $downloads[$name] ??= sprintf('~DGR:%s.GRF,%s,%s,%s', $name, $totalBytes, $bytesPerRow, $data);

            return '^XGR:'.$name.'.GRF,1,1';
        }, $zpl);

        if ($downloads === [] || $recalled === null) {
            return $zpl;
        }

        return implode("\n", $downloads)."\n".$recalled;
    }
}
