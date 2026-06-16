<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\LabelPrinterService;
use Mojito\Label\ShellCommandRunner;
use Mojito\Label\ZplBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LabelPrinterServiceTest extends TestCase
{
    public function test_build_zpl_default_label_contains_required_fields(): void
    {
        $service = new LabelPrinterService;

        $zpl = $service->buildZpl([
            'title' => 'CAVALLINI SERVICE',
            'product' => 'Test',
            'serial' => 'ABC123',
            'barcode' => 'ABC123456789',
        ]);

        $this->assertStringContainsString('^XA', $zpl);
        $this->assertStringContainsString('^XZ', $zpl);
        $this->assertStringContainsString('CAVALLINI SERVICE', $zpl);
        $this->assertStringContainsString('Prodotto: Test', $zpl);
        $this->assertStringContainsString('Seriale: ABC123', $zpl);
        $this->assertStringContainsString('^BCN,100,Y,N,N', $zpl);
        $this->assertStringContainsString('ABC123456789', $zpl);
    }

    public function test_build_zpl_from_template_uses_named_data_sources(): void
    {
        $builder = new ZplBuilder;
        $service = new LabelPrinterService($builder);

        $template = [
            'labelWidth' => 600,
            'labelHeight' => 400,
            'elements' => [
                [
                    'type' => 'text',
                    'x' => 10,
                    'y' => 10,
                    'dataSource' => 'title',
                ],
                [
                    'type' => 'barcode',
                    'x' => 10,
                    'y' => 50,
                    'dataSource' => 'barcode',
                ],
            ],
        ];

        $zpl = $service->buildZpl([
            'template' => $template,
            'values' => [
                'title' => 'MOJITO POC',
                'barcode' => '12345',
            ],
        ]);

        $this->assertStringContainsString('MOJITO POC', $zpl);
        $this->assertStringContainsString('^BCN', $zpl);
        $this->assertStringContainsString('12345', $zpl);
    }

    public function test_build_zpl_ignora_values_non_array(): void
    {
        $service = new LabelPrinterService;

        $zpl = $service->buildZpl([
            'template' => [
                'elements' => [
                    ['type' => 'text', 'x' => 0, 'y' => 0, 'staticValue' => 'OK'],
                ],
            ],
            'values' => 'invalid',
        ]);

        $this->assertStringContainsString('OK', $zpl);
    }

    public function test_default_printer_name(): void
    {
        $service = new LabelPrinterService;

        $this->assertSame('Citizen_CL_S703Z', $service->getPrinterName());
    }

    public function test_set_printer_name(): void
    {
        $service = new LabelPrinterService;
        $service->setPrinterName('Altra_Stampante');

        $this->assertSame('Altra_Stampante', $service->getPrinterName());
    }

    public function test_print_zpl_temp_file_creation_failure(): void
    {
        $service = new LabelPrinterService(
            commandRunner: new ShellCommandRunner(static fn (): array => ['output' => [], 'code' => 0]),
            tempFileFactory: static fn (): string|false => false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Impossibile creare file temporaneo');

        $service->printZpl('^XA^XZ');
    }

    public function test_print_zpl_write_failure(): void
    {
        $service = new LabelPrinterService(
            commandRunner: new ShellCommandRunner(static fn (): array => ['output' => [], 'code' => 0]),
            tempFileFactory: static fn (): string => sys_get_temp_dir(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Impossibile scrivere ZPL');

        $service->printZpl('^XA^XZ');
    }

    public function test_print_zpl_success(): void
    {
        $runner = new ShellCommandRunner(static fn (): array => ['output' => ['ok'], 'code' => 0]);
        $service = new LabelPrinterService(commandRunner: $runner);

        $service->printZpl('^XA^XZ');

        $this->assertTrue(true);
    }

    public function test_print_zpl_windows_tries_fallback_methods(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Test specifico Windows.');
        }

        $attempt = 0;
        $runner = new ShellCommandRunner(function () use (&$attempt): array {
            $attempt++;

            return ['output' => $attempt === 1 ? ['raw failed'] : ['ok'], 'code' => $attempt === 1 ? 1 : 0];
        });
        $service = new LabelPrinterService(commandRunner: $runner);

        $service->printZpl('^XA^XZ');

        $this->assertSame(2, $attempt);
    }

    public function test_print_zpl_failure(): void
    {
        $runner = new ShellCommandRunner(static fn (): array => ['output' => ['lp failed'], 'code' => 1]);
        $service = new LabelPrinterService(commandRunner: $runner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Errore stampa etichetta');

        $service->printZpl('^XA^XZ');
    }

    public function test_list_printers_parses_lpstat_output(): void
    {
        $runner = new ShellCommandRunner(function (string $command): array {
            if (str_contains($command, 'lpstat -a')) {
                return ['output' => ['Citizen_CL_S703Z accepting requests since Mon 01 Jan 2024'], 'code' => 0];
            }

            return ['output' => [], 'code' => 1];
        });
        $service = new LabelPrinterService(commandRunner: $runner);

        $this->assertSame(['Citizen_CL_S703Z'], $service->listPrinters());
    }

    public function test_list_printers_returns_empty_when_none_found(): void
    {
        $runner = new ShellCommandRunner(static fn (): array => ['output' => [], 'code' => 1]);
        $service = new LabelPrinterService(commandRunner: $runner);

        $this->assertSame([], $service->listPrinters());
    }

    public function test_list_printers_info_includes_platform(): void
    {
        $service = new LabelPrinterService(
            commandRunner: new ShellCommandRunner(static fn (): array => ['output' => [], 'code' => 1]),
        );

        $info = $service->listPrintersInfo();

        $this->assertSame([], $info['printers']);
        $this->assertSame(PHP_OS_FAMILY, $info['platform']);
    }

    public function test_print_label_uses_build_zpl(): void
    {
        $runner = new ShellCommandRunner(static fn (): array => ['output' => [], 'code' => 0]);
        $service = new LabelPrinterService(commandRunner: $runner);

        $service->printLabel(['title' => 'X', 'product' => 'P', 'serial' => 'S', 'barcode' => 'B']);

        $this->assertTrue(true);
    }
}
