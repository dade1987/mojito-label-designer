#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Script CLI per stampa etichette ZPL di test.
 *
 * Uso:
 *   php bin/print-label.php
 *   php bin/print-label.php --printer=Citizen_CL_S703Z --title="TEST"
 *   php bin/print-label.php --preview
 */

use Mojito\Label\LabelPrinterService;

require dirname(__DIR__).'/vendor/autoload.php';

$options = getopt('', [
    'printer::',
    'title::',
    'product::',
    'serial::',
    'barcode::',
    'preview',
    'help',
]);

if (isset($options['help'])) {
    echo <<<'HELP'
Mojito - stampa etichetta ZPL via CUPS

Opzioni:
  --printer=NOME   Nome coda CUPS (default: Citizen_CL_S703Z)
  --title=TESTO    Titolo etichetta
  --product=TESTO  Nome prodotto
  --serial=TESTO   Numero seriale
  --barcode=TESTO  Dati barcode Code 128
  --preview        Mostra ZPL senza stampare
  --help           Mostra questo messaggio

HELP;
    exit(0);
}

$data = [
    'title' => $options['title'] ?? 'CAVALLINI SERVICE',
    'product' => $options['product'] ?? 'Test',
    'serial' => $options['serial'] ?? 'ABC123',
    'barcode' => $options['barcode'] ?? 'ABC123456789',
];

$service = new LabelPrinterService;

if (isset($options['printer']) && is_string($options['printer'])) {
    $service->setPrinterName($options['printer']);
}

$zpl = $service->buildZpl($data);

if (isset($options['preview'])) {
    echo $zpl.PHP_EOL;
    exit(0);
}

try {
    $service->printZpl($zpl);
    echo 'Etichetta inviata a '.$service->getPrinterName().PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Errore: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
