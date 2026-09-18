<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\ApiHandler;
use Mojito\Label\LabelMedia;
use Mojito\Label\LabelPrinterService;
use Mojito\Label\PrinterPlatform;
use Mojito\Label\ShellCommandRunner;
use Mojito\Label\TemplateRepository;
use PHPUnit\Framework\TestCase;

/**
 * Piu' etichette in una richiesta devono uscire come UN lavoro di stampa.
 *
 * Mandarne uno per etichetta fa ripartire la stampante ogni volta: si ferma,
 * riallinea, riprende. Con una serie di cento pacchi la stampa non va mai
 * dritta.
 */
final class SinglePrintJobTest extends TestCase
{
    /** @var list<string> */
    private array $commands = [];

    /** @var list<string> */
    private array $payloads = [];

    private function runner(): ShellCommandRunner
    {
        return new ShellCommandRunner(function (string $command): array {
            $this->commands[] = $command;

            if (preg_match_all("/'([^']+)'/", $command, $matches) > 0) {
                $file = end($matches[1]);
                if (is_string($file) && is_file($file)) {
                    $this->payloads[] = (string) file_get_contents($file);
                }
            }

            return ['output' => ['request id is P-1 (1 file(s))'], 'code' => 0];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function zplJob(string $serial): array
    {
        return [
            'template' => [
                'labelWidth' => 400,
                'labelHeight' => 200,
                'dpi' => 203,
                'elements' => [['type' => 'text', 'x' => 10, 'y' => 10, 'dataSource' => 'serial']],
            ],
            'values' => ['serial' => $serial],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function graphicJob(string $serial): array
    {
        return ['printMode' => 'graphic'] + $this->zplJob($serial);
    }

    private function printCommands(): int
    {
        return count(array_filter($this->commands, static fn (string $c): bool => str_starts_with($c, 'lp ')));
    }

    public function test_copies_of_a_zpl_label_are_one_print_job(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: 'Citizen');

        $service->printJob($this->zplJob('CHL1'), 3);

        $this->assertSame(1, $this->printCommands());
        $this->assertSame(3, substr_count($this->payloads[0], '^XA'));
        $this->assertSame(3, substr_count($this->payloads[0], '^FDCHL1^FS'));
    }

    /**
     * Le copie restano accanto alla loro etichetta: 1, 1, 2, 2, 3, 3. Chi
     * attacca le etichette pacco per pacco le trova gia' in ordine.
     */
    public function test_a_series_of_zpl_labels_is_one_print_job_in_order(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: 'Citizen');

        $service->printJobs([$this->zplJob('CHL1'), $this->zplJob('CHL2'), $this->zplJob('CHL3')], 2);

        $this->assertSame(1, $this->printCommands());
        preg_match_all('/\^FD(CHL\d)\^FS/', $this->payloads[0], $matches);
        $this->assertSame(['CHL1', 'CHL1', 'CHL2', 'CHL2', 'CHL3', 'CHL3'], $matches[1]);
    }

    public function test_an_empty_series_prints_nothing(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: 'Citizen');

        $service->printJobs([], 2);

        $this->assertSame([], $this->commands);
    }

    public function test_the_series_has_the_same_copy_limit(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: 'Citizen');

        $this->expectException(\RuntimeException::class);
        $service->printJobs(array_fill(0, 11, $this->zplJob('X')), 100);
    }

    /**
     * Le etichette disegnate sono immagini: le copie della stessa si chiedono
     * alla coda in un colpo solo (lp -n), invece di rimandarla N volte.
     */
    public function test_copies_of_a_drawn_label_are_one_print_job(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: 'Munbyn_ITPP941P');

        $service->printJob($this->graphicJob('CHL1'), 3);

        $this->assertSame(1, $this->printCommands());
        $this->assertStringContainsString(' -n 3 ', $this->commands[0]);
    }

    public function test_a_series_of_drawn_labels_is_one_job_per_label_with_its_copies(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: 'Munbyn_ITPP941P');

        $service->printJobs([$this->graphicJob('CHL1'), $this->graphicJob('CHL2')], 2);

        $this->assertSame(2, $this->printCommands());
        foreach ($this->commands as $command) {
            $this->assertStringContainsString(' -n 2 ', $command);
        }
    }

    public function test_the_graphic_command_asks_for_copies_only_when_needed(): void
    {
        $media = LabelMedia::fromTemplate(['labelWidth' => 400, 'labelHeight' => 200, 'dpi' => 203]);

        $this->assertStringNotContainsString(' -n ', PrinterPlatform::buildUnixGraphicPrintCommand('P', '/tmp/a.png', $media));
        $this->assertStringContainsString(' -n 4 ', PrinterPlatform::buildUnixGraphicPrintCommand('P', '/tmp/a.png', $media, 4));
        $this->assertStringContainsString(" -Copies '4'", PrinterPlatform::buildWindowsGraphicPrintCommands('P', 'C:\\a.png', $media, 'C:\\tmp', 4)[0]);
        $this->assertStringNotContainsString('-Copies', PrinterPlatform::buildWindowsGraphicPrintCommands('P', 'C:\\a.png', $media, 'C:\\tmp')[0]);
    }

    public function test_the_print_api_sends_a_series_as_one_job(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: 'Citizen');
        $handler = new ApiHandler($service, new TemplateRepository(sys_get_temp_dir().'/mojito-single-'.uniqid()));

        $result = $handler->handle('POST', '/api/print', (string) json_encode([
            'printer' => 'Citizen',
            'template' => $this->zplJob('X')['template'],
            'jobs' => [['serial' => 'CHL1'], ['serial' => 'CHL2']],
            'copies' => 2,
        ]));

        $this->assertSame(200, $result['status']);
        $this->assertSame(4, $result['payload']['printed']);
        $this->assertSame(1, $this->printCommands());
        $this->assertSame(4, substr_count($this->payloads[0], '^XA'));
    }

    public function test_the_print_api_sends_copies_of_raw_zpl_as_one_job(): void
    {
        $service = new LabelPrinterService(commandRunner: $this->runner(), printerName: 'Citizen');
        $handler = new ApiHandler($service, new TemplateRepository(sys_get_temp_dir().'/mojito-single-'.uniqid()));

        $result = $handler->handle('POST', '/api/print', (string) json_encode([
            'printer' => 'Citizen',
            'zpl' => '^XA^FDRAW^FS^XZ',
            'copies' => 3,
        ]));

        $this->assertSame(200, $result['status']);
        $this->assertSame(1, $this->printCommands());
        $this->assertSame('^XA^FDRAW^FS^XZ^XA^FDRAW^FS^XZ^XA^FDRAW^FS^XZ', $this->payloads[0]);
    }
}
