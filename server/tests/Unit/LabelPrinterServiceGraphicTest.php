<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\LabelPrinterService;
use Mojito\Label\ShellCommandRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * La stampa "normale" (non ZPL): il server disegna l'etichetta e la manda alla
 * coda di stampa di sistema come immagine.
 */
final class LabelPrinterServiceGraphicTest extends TestCase
{
    /** @var list<string> */
    private array $commands = [];

    /** @var list<array{path: string, bytes: string}> */
    private array $printedFiles = [];

    private function runner(int $code = 0): ShellCommandRunner
    {
        return new ShellCommandRunner(function (string $command) use ($code): array {
            $this->commands[] = $command;

            if (preg_match('/\'([^\']+\.png)\'/', $command, $matches) === 1 && is_file($matches[1])) {
                $this->printedFiles[] = ['path' => $matches[1], 'bytes' => (string) file_get_contents($matches[1])];
            }

            return ['output' => $code === 0 ? ['request id is P-1 (1 file(s))'] : ['lp: Impossibile stampare'], 'code' => $code];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function graphicJob(): array
    {
        return [
            'printMode' => 'graphic',
            'template' => [
                'labelWidth' => 400,
                'labelHeight' => 200,
                'dpi' => 203,
                'elements' => [
                    ['type' => 'text', 'x' => 10, 'y' => 10, 'fontHeight' => 30, 'fontWidth' => 30, 'dataSource' => 'serial'],
                ],
            ],
            'values' => ['serial' => 'CHL12251'],
        ];
    }

    public function test_it_sends_a_png_to_the_print_queue_instead_of_zpl(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: 'Munbyn_ITPP941P');

        $service->printLabel($this->graphicJob());

        $this->assertCount(1, $this->commands);
        $this->assertStringContainsString('lp -d', $this->commands[0]);
        $this->assertStringNotContainsString('-o raw', $this->commands[0]);
        $this->assertCount(1, $this->printedFiles);
        $this->assertStringStartsWith("\x89PNG", $this->printedFiles[0]['bytes']);
    }

    public function test_the_printed_image_has_the_size_of_the_label(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: 'P1');

        $service->printLabel($this->graphicJob());

        $image = imagecreatefromstring($this->printedFiles[0]['bytes']);
        $this->assertInstanceOf(\GdImage::class, $image);
        $this->assertSame(400, imagesx($image));
        $this->assertSame(200, imagesy($image));
    }

    public function test_the_temporary_image_is_removed_afterwards(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: 'P1');

        $service->printLabel($this->graphicJob());

        $this->assertFileDoesNotExist($this->printedFiles[0]['path']);
    }

    public function test_it_records_how_the_label_was_printed(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: 'P1');

        $service->printLabel($this->graphicJob());

        $this->assertSame('graphic', $service->getLastPrintMethod());
    }

    public function test_a_failing_queue_is_reported_with_its_output(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(1), printerName: 'P1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Impossibile stampare/');

        $service->printLabel($this->graphicJob());
    }

    public function test_the_print_mode_can_come_from_the_layout(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: 'P1');

        $job = $this->graphicJob();
        unset($job['printMode']);
        $job['template']['printMode'] = 'graphic';

        $service->printLabel($job);

        $this->assertStringNotContainsString('-o raw', $this->commands[0]);
    }

    public function test_without_a_print_mode_nothing_changes_and_zpl_is_used(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: 'P1');

        $job = $this->graphicJob();
        unset($job['printMode']);

        $service->printLabel($job);

        $this->assertStringContainsString('-o raw', $this->commands[0]);
        $this->assertStringStartsWith('^XA', $this->printedFiles[0]['bytes'] ?? '^XA');
    }

    public function test_an_unknown_print_mode_falls_back_to_zpl(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: 'P1');

        $job = $this->graphicJob();
        $job['printMode'] = 'olografica';

        $service->printLabel($job);

        $this->assertStringContainsString('-o raw', $this->commands[0]);
    }

    /**
     * Le copie si chiedono alla coda in un lavoro solo: rimandare l'immagine
     * N volte faceva ripartire la stampante a ogni etichetta.
     */
    public function test_copies_are_one_print_job(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: 'P1');

        $service->printJob($this->graphicJob(), 3);

        $this->assertCount(1, $this->commands);
        $this->assertStringContainsString(' -n 3 ', $this->commands[0]);
    }

    public function test_the_number_of_copies_is_kept_sane(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: 'P1');

        $service->printJob($this->graphicJob(), 0);

        $this->assertCount(1, $this->commands);
    }

    public function test_too_many_copies_are_refused(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: 'P1');

        $this->expectException(RuntimeException::class);

        $service->printJob($this->graphicJob(), 5000);
    }

    public function test_the_png_can_be_had_without_printing_it(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: 'P1');

        $png = $service->renderPng($this->graphicJob());

        $this->assertStringStartsWith("\x89PNG", $png);
        $this->assertSame([], $this->commands);
    }

    public function test_printing_without_a_printer_is_refused(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: ' ');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Nessuna stampante/');

        $service->printLabel($this->graphicJob());
    }
}
