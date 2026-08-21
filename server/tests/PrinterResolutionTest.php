<?php

declare(strict_types=1);

namespace Mojito\Label\Tests;

use Mojito\Label\PrinterResolution;
use PHPUnit\Framework\TestCase;

/**
 * La risoluzione di stampa di una stampante conosciuta.
 *
 * Disegnare a 203 punti per pollice un'etichetta che verra' stampata a 300
 * significa mandarla in stampa di misura sbagliata: il disegno e' giusto sullo
 * schermo e storto sulla carta. Se il nome della stampante dice il modello,
 * quel numero si puo' sapere invece di farlo indovinare.
 */
final class PrinterResolutionTest extends TestCase
{
    public function test_it_knows_the_citizen_of_the_workshop(): void
    {
        // La CL-S703 e' una 300 dpi: e' quella installata in reparto.
        self::assertSame(300, PrinterResolution::forPrinter('Citizen_CL_S703Z'));
    }

    public function test_it_reads_the_model_however_the_name_is_written(): void
    {
        self::assertSame(300, PrinterResolution::forPrinter('citizen cl-s703'));
        self::assertSame(300, PrinterResolution::forPrinter('CL_S703III'));
    }

    public function test_the_lower_model_of_the_same_family_is_203(): void
    {
        // CL-S700 e CL-S700 II sono 203 dpi: stessa famiglia, risoluzione diversa.
        self::assertSame(203, PrinterResolution::forPrinter('Citizen_CL_S700'));
    }

    public function test_it_knows_the_apex_of_the_workshop(): void
    {
        // La Apex installata in reparto stampa a 600 dpi.
        self::assertSame(600, PrinterResolution::forPrinter('Apex_600'));
        self::assertSame(600, PrinterResolution::forPrinter('APEX label printer'));
    }

    public function test_an_unknown_printer_admits_it(): void
    {
        // Meglio nessun valore che uno inventato: chi disegna lo imposta a mano.
        self::assertNull(PrinterResolution::forPrinter('Stampante Ufficio'));
        self::assertNull(PrinterResolution::forPrinter(''));
    }

    public function test_the_installation_can_declare_its_own_printers(): void
    {
        putenv('MOJITO_PRINTER_DPI=Zebra_ZT230=203,Etichettatrice_Nuova=600');

        try {
            self::assertSame(600, PrinterResolution::forPrinter('Etichettatrice_Nuova'));
            self::assertSame(203, PrinterResolution::forPrinter('Zebra_ZT230'));
        } finally {
            putenv('MOJITO_PRINTER_DPI');
        }
    }

    public function test_a_declared_printer_wins_over_the_model_guessed_from_the_name(): void
    {
        putenv('MOJITO_PRINTER_DPI=Citizen_CL_S703Z=203');

        try {
            // Chi conosce il proprio impianto ha ragione sul riconoscimento
            // automatico.
            self::assertSame(203, PrinterResolution::forPrinter('Citizen_CL_S703Z'));
        } finally {
            putenv('MOJITO_PRINTER_DPI');
        }
    }
}
