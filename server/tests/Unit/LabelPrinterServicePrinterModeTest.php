<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\LabelPrinterService;
use Mojito\Label\ShellCommandRunner;
use PHPUnit\Framework\TestCase;

/**
 * La strada di stampa segue la stampante anche quando a stampare non è il
 * designer: le etichette lanciate dal pannello lotti portano solo il layout e
 * il nome della stampante, e un layout salvato mesi fa su una Citizen non deve
 * mandare ZPL a una Munbyn (uscirebbe carta bianca, senza errori).
 */
final class LabelPrinterServicePrinterModeTest extends TestCase
{
    /** @var list<string> */
    private array $commands = [];

    private function service(string $printer): LabelPrinterService
    {
        return new LabelPrinterService(
            commandRunner: new ShellCommandRunner(function (string $command): array {
                $this->commands[] = $command;

                return ['output' => ['ok'], 'code' => 0];
            }),
            printerName: $printer,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function job(?string $templateMode): array
    {
        $template = [
            'labelWidth' => 300,
            'labelHeight' => 150,
            'dpi' => 203,
            'elements' => [['type' => 'text', 'x' => 5, 'y' => 5, 'fontHeight' => 20, 'fontWidth' => 20, 'staticValue' => 'X']],
        ];

        if ($templateMode !== null) {
            $template['printMode'] = $templateMode;
        }

        return ['template' => $template];
    }

    public function test_a_printer_that_cannot_read_zpl_gets_the_drawn_label(): void
    {
        $service = $this->service('Munbyn ITPP941P');

        $this->assertSame(LabelPrinterService::MODE_GRAPHIC, $service->resolvePrintMode($this->job(null)));
    }

    public function test_the_printer_wins_over_a_layout_saved_for_another_one(): void
    {
        $service = $this->service('Munbyn ITPP941P');

        $service->printLabel($this->job('zpl'));

        $this->assertStringNotContainsString('-o raw', $this->commands[0]);
    }

    public function test_a_zpl_printer_keeps_getting_zpl(): void
    {
        $service = $this->service('Citizen_CL_S703Z');

        $service->printLabel($this->job('graphic'));

        $this->assertStringContainsString('-o raw', $this->commands[0]);
    }

    public function test_an_explicit_request_still_decides(): void
    {
        $service = $this->service('Munbyn ITPP941P');

        $this->assertSame(
            LabelPrinterService::MODE_ZPL,
            $service->resolvePrintMode(['printMode' => 'zpl'] + $this->job(null))
        );
    }

    public function test_an_unknown_printer_leaves_the_choice_to_the_layout(): void
    {
        $service = $this->service('Stampante Sconosciuta');

        $this->assertSame(LabelPrinterService::MODE_GRAPHIC, $service->resolvePrintMode($this->job('graphic')));
        $this->assertSame(LabelPrinterService::MODE_ZPL, $service->resolvePrintMode($this->job(null)));
    }
}
