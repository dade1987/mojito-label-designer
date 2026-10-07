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
 * lo ZPL gia' composto (serie e copie comprese, in un pezzo solo) oppure,
 * per le stampanti che lo ZPL non lo parlano (Munbyn), le etichette gia'
 * disegnate.
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
     * Come va stampata la destinazione ("zpl" o "graphic"), oppure null se
     * non si sa: in quel caso decidono la richiesta o il layout.
     */
    public function printMode(string $printer): ?string;

    /**
     * Consegna lo ZPL. `labels` e' quante etichette contiene, copie comprese.
     *
     * @return array{status: string} "printed" o "queued"
     */
    public function send(string $printer, string $zpl, int $labels): array;

    /**
     * Consegna le etichette gia' disegnate, una PNG per etichetta, tutte
     * della stessa misura; ognuna va stampata `copies` volte.
     *
     * @param  list<string>  $pngs
     * @return array{status: string} "printed" o "queued"
     */
    public function sendImages(string $printer, array $pngs, LabelMedia $media, int $copies): array;
}
