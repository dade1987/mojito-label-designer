<?php

declare(strict_types=1);

namespace Mojito\Label;

/**
 * Destinazioni di stampa oltre alle stampanti del server.
 *
 * Mojito da solo stampa sulle code del sistema operativo (CUPS, Winspool).
 * Chi lo ospita puo' offrirne altre - una stampante di rete, la stampante
 * collegata a un PC di reparto - senza che Mojito sappia come si
 * raggiungono: le mostra nell'elenco e, quando se ne sceglie una, consegna
 * lo ZPL gia' composto (serie e copie comprese, in un pezzo solo).
 */
interface PrintDestinations
{
    /**
     * Le destinazioni da aggiungere all'elenco delle stampanti.
     *
     * @return list<array{value: string, label: string}>
     */
    public function printers(): array;

    /** Vero se la stampante scelta e' una di queste destinazioni. */
    public function handles(string $printer): bool;

    /**
     * Consegna lo ZPL. `labels` e' quante etichette contiene, copie comprese.
     *
     * @return array{status: string} "printed" o "queued"
     */
    public function send(string $printer, string $zpl, int $labels): array;
}
