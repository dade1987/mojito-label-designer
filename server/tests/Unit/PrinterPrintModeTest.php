<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\PrinterPrintMode;
use PHPUnit\Framework\TestCase;

/**
 * La strada di stampa di una stampante conosciuta: comandi ZPL per chi li
 * parla, etichetta disegnata per chi no. Sbagliare non da' errori, esce solo
 * carta bianca: se il nome dice il modello, non c'e' motivo di far indovinare.
 */
final class PrinterPrintModeTest extends TestCase
{
    public function test_the_citizen_of_the_workshop_speaks_zpl(): void
    {
        self::assertSame('zpl', PrinterPrintMode::forPrinter('Citizen_CL_S703Z'));
        self::assertSame('zpl', PrinterPrintMode::forPrinter('citizen cl-s703'));
    }

    public function test_the_munbyn_wants_the_drawn_label(): void
    {
        self::assertSame('graphic', PrinterPrintMode::forPrinter('Munbyn ITPP941P'));
        self::assertSame('graphic', PrinterPrintMode::forPrinter('ITPP941P (USB)'));
    }

    public function test_zebra_and_apex_speak_zpl(): void
    {
        self::assertSame('zpl', PrinterPrintMode::forPrinter('Zebra_ZT230'));
        self::assertSame('zpl', PrinterPrintMode::forPrinter('Apix 251 (600DPI)'));
    }

    public function test_an_unknown_printer_admits_it(): void
    {
        self::assertNull(PrinterPrintMode::forPrinter('Stampante Ufficio'));
        self::assertNull(PrinterPrintMode::forPrinter(''));
    }

    public function test_the_installation_can_declare_its_own_printers(): void
    {
        putenv('MOJITO_PRINTER_MODE=Etichettatrice_Nuova=graphic,Stampante_Ufficio=ZPL');

        try {
            self::assertSame('graphic', PrinterPrintMode::forPrinter('Etichettatrice_Nuova'));
            self::assertSame('zpl', PrinterPrintMode::forPrinter('Stampante_Ufficio'));
        } finally {
            putenv('MOJITO_PRINTER_MODE');
        }
    }

    public function test_a_declared_printer_wins_over_the_model_guessed_from_the_name(): void
    {
        putenv('MOJITO_PRINTER_MODE=Citizen_CL_S703Z=graphic');

        try {
            self::assertSame('graphic', PrinterPrintMode::forPrinter('Citizen_CL_S703Z'));
        } finally {
            putenv('MOJITO_PRINTER_MODE');
        }
    }

    public function test_a_declaration_with_a_mode_that_does_not_exist_is_ignored(): void
    {
        putenv('MOJITO_PRINTER_MODE=Citizen_CL_S703Z=pdf,rotta');

        try {
            self::assertSame('zpl', PrinterPrintMode::forPrinter('Citizen_CL_S703Z'));
        } finally {
            putenv('MOJITO_PRINTER_MODE');
        }
    }
}
