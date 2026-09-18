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
 */
final class ZplBatch
{
    private const CONTINUE = '^MMR';

    private const LAST = '^MMT';

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

        return $result;
    }
}
