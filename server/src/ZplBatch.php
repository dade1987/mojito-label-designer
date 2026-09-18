<?php

declare(strict_types=1);

namespace Mojito\Label;

/**
 * Piu' etichette ZPL che escono di fila, senza che la carta torni indietro
 * fra l'una e l'altra.
 *
 * Dopo ogni formato (^XA...^XZ) la stampante in modalita' strappo porta
 * l'etichetta alla barra e, prima della successiva, riavvolge: la serie esce
 * "una, indietro, un'altra". La Citizen in emulazione ZPL non conosce ^XB,
 * ma accetta ^MMR (tear on: l'etichetta dopo resta sotto la testina, niente
 * ritorno). Quindi ^MMR in tutte tranne l'ultima e ^MMT (strappo, il valore
 * di serie) nell'ultima, che si ferma alla barra come sempre e lascia la
 * stampante com'era.
 *
 * Le immagini (^GF) vanno caricate una volta sola: la Citizen ^GF non lo
 * supporta davvero, lo emula rielaborando l'immagine a ogni etichetta, e in
 * una serie resta indietro e si ferma. In una serie ogni immagine si carica
 * in memoria con ~DG prima della prima etichetta e ogni etichetta la
 * richiama con ^XG, che la Citizen supporta.
 */
final class ZplBatch
{
    private const CONTINUE = '^MMR';

    private const LAST = '^MMT';

    private const GRAPHIC_FIELD = '/\^GFA,\d+,(\d+),(\d+),([0-9A-F]+)/i';

    public static function repeat(string $zpl, int $copies): string
    {
        return self::continuous(str_repeat($zpl, max(1, $copies)));
    }

    public static function continuous(string $zpl): string
    {
        $parts = preg_split('/(\^XA)/i', $zpl, -1, PREG_SPLIT_DELIM_CAPTURE);

        // Un formato solo (o nessuno): non c'e' niente da tenere di fila.
        if ($parts === false || count($parts) < 5) {
            return $zpl;
        }

        $last = count($parts) - 2;
        $result = $parts[0];

        for ($i = 1; $i < count($parts); $i += 2) {
            $result .= $parts[$i].($i === $last ? self::LAST : self::CONTINUE).$parts[$i + 1];
        }

        return self::storeGraphics($result);
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
