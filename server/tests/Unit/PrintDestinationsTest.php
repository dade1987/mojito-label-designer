<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\ApiHandler;
use Mojito\Label\LabelPrinterService;
use Mojito\Label\PrintDestinations;
use Mojito\Label\ShellCommandRunner;
use Mojito\Label\TemplateRepository;
use PHPUnit\Framework\TestCase;

/**
 * Oltre alle stampanti del server, chi ospita Mojito puo' offrire altre
 * destinazioni (una stampante di rete, la stampante collegata a un PC di
 * reparto). Mojito le mostra nell'elenco e, quando se ne sceglie una, le
 * consegna lo ZPL gia' composto invece di stamparlo lui.
 */
final class PrintDestinationsTest extends TestCase
{
    /** @var list<string> */
    private array $commands = [];

    /** @var list<array{printer: string, zpl: string, labels: int}> */
    private array $sent = [];

    protected function tearDown(): void
    {
        putenv('MOJITO_PASSWORD');

        parent::tearDown();
    }

    private function destinations(): PrintDestinations
    {
        $test = $this;

        return new class($test) implements PrintDestinations
        {
            public function __construct(private readonly PrintDestinationsTest $test) {}

            public function printers(): array
            {
                return [
                    ['value' => 'ip:192.168.1.50:9100', 'label' => 'Rete · 192.168.1.50:9100'],
                    ['value' => 'pc:SURFACE9|Citizen CL-S703', 'label' => 'SURFACE9 · Citizen CL-S703'],
                ];
            }

            public function handles(string $printer): bool
            {
                return str_starts_with($printer, 'ip:') || str_starts_with($printer, 'pc:');
            }

            public function send(string $printer, string $zpl, int $labels): array
            {
                $this->test->record($printer, $zpl, $labels);

                return ['status' => str_starts_with($printer, 'pc:') ? 'queued' : 'printed'];
            }
        };
    }

    public function record(string $printer, string $zpl, int $labels): void
    {
        $this->sent[] = ['printer' => $printer, 'zpl' => $zpl, 'labels' => $labels];
    }

    private function handler(?PrintDestinations $destinations = null): ApiHandler
    {
        $service = new LabelPrinterService(
            commandRunner: new ShellCommandRunner(function (string $command): array {
                $this->commands[] = $command;

                if (str_contains($command, 'lpstat -a')) {
                    return ['output' => ['Citizen_CL_S703Z accepting requests since oggi'], 'code' => 0];
                }

                return ['output' => [], 'code' => str_contains($command, 'lpstat') ? 1 : 0];
            }),
            printerName: 'Citizen_CL_S703Z',
        );

        return new ApiHandler($service, new TemplateRepository(sys_get_temp_dir().'/mojito-dest-'.uniqid()), $destinations);
    }

    /**
     * @return array<string, mixed>
     */
    private function template(): array
    {
        return [
            'labelWidth' => 400,
            'labelHeight' => 200,
            'dpi' => 203,
            'elements' => [['type' => 'text', 'x' => 10, 'y' => 10, 'dataSource' => 'serial']],
        ];
    }

    public function test_the_extra_destinations_appear_next_to_the_server_printers(): void
    {
        $result = $this->handler($this->destinations())->handle('GET', '/api/printers');

        $payload = $result['payload'];
        $this->assertSame(['Citizen_CL_S703Z', 'ip:192.168.1.50:9100', 'pc:SURFACE9|Citizen CL-S703'], $payload['printers']);
        $this->assertSame('SURFACE9 · Citizen CL-S703', $payload['printerLabels']['pc:SURFACE9|Citizen CL-S703']);
        // Queste destinazioni ricevono ZPL: il designer imposta il layout di conseguenza.
        $this->assertSame('zpl', $payload['printerModes']['ip:192.168.1.50:9100']);
        $this->assertSame('zpl', $payload['printerModes']['pc:SURFACE9|Citizen CL-S703']);
    }

    public function test_without_extra_destinations_nothing_changes(): void
    {
        $payload = $this->handler()->handle('GET', '/api/printers')['payload'];

        $this->assertSame(['Citizen_CL_S703Z'], $payload['printers']);
        $this->assertArrayNotHasKey('printerLabels', $payload);
    }

    /**
     * La serie intera, copie comprese, arriva alla destinazione in un colpo
     * solo: e' li' che diventa un unico lavoro di stampa.
     */
    public function test_a_series_goes_to_the_chosen_destination_as_one_zpl(): void
    {
        $result = $this->handler($this->destinations())->handle('POST', '/api/print', (string) json_encode([
            'printer' => 'pc:SURFACE9|Citizen CL-S703',
            'template' => $this->template(),
            'jobs' => [['serial' => 'CHL1'], ['serial' => 'CHL2']],
            'copies' => 2,
        ]));

        $this->assertSame(200, $result['status']);
        $this->assertSame('queued', $result['payload']['status']);
        $this->assertSame(4, $result['payload']['printed']);
        $this->assertSame('zpl', $result['payload']['mode']);
        $this->assertSame('pc:SURFACE9|Citizen CL-S703', $result['payload']['printer']);

        $this->assertCount(1, $this->sent);
        preg_match_all('/\^FD(CHL\d)\^FS/', $this->sent[0]['zpl'], $matches);
        $this->assertSame(['CHL1', 'CHL1', 'CHL2', 'CHL2'], $matches[1]);
        $this->assertSame(4, $this->sent[0]['labels']);

        // Il server non ha stampato niente di suo.
        $this->assertSame([], array_values(array_filter($this->commands, static fn (string $c): bool => str_starts_with($c, 'lp '))));
    }

    /**
     * Anche un layout disegnato per una stampante a immagine va in ZPL: quelle
     * destinazioni ricevono solo ZPL.
     */
    public function test_a_graphic_layout_is_sent_as_zpl_to_those_destinations(): void
    {
        $result = $this->handler($this->destinations())->handle('POST', '/api/print', (string) json_encode([
            'printer' => 'ip:192.168.1.50:9100',
            'printMode' => 'graphic',
            'template' => $this->template(),
            'values' => ['serial' => 'CHL9'],
        ]));

        $this->assertSame('printed', $result['payload']['status']);
        $this->assertStringStartsWith('^XA', $this->sent[0]['zpl']);
        $this->assertStringContainsString('^FDCHL9^FS', $this->sent[0]['zpl']);
    }

    public function test_raw_zpl_with_copies_goes_to_the_destination_in_one_piece(): void
    {
        $this->handler($this->destinations())->handle('POST', '/api/print', (string) json_encode([
            'printer' => 'ip:192.168.1.50:9100',
            'zpl' => '^XA^FDRAW^FS^XZ',
            'copies' => 3,
        ]));

        $this->assertSame('^XA^MMR^FDRAW^FS^XZ^XA^MMR^FDRAW^FS^XZ^XA^MMT^FDRAW^FS^XZ', $this->sent[0]['zpl']);
        $this->assertSame(3, $this->sent[0]['labels']);
    }

    public function test_a_server_printer_is_still_printed_by_the_server(): void
    {
        $result = $this->handler($this->destinations())->handle('POST', '/api/print', (string) json_encode([
            'printer' => 'Citizen_CL_S703Z',
            'template' => $this->template(),
            'values' => ['serial' => 'CHL1'],
        ]));

        $this->assertSame('printed', $result['payload']['status']);
        $this->assertSame([], $this->sent);
        $this->assertCount(1, array_filter($this->commands, static fn (string $c): bool => str_starts_with($c, 'lp ')));
    }

    /**
     * La password del designer vale anche per le destinazioni aggiuntive:
     * non devono diventare una porta laterale.
     */
    public function test_the_designer_password_protects_the_extra_destinations_too(): void
    {
        putenv('MOJITO_PASSWORD=segreta');

        $result = $this->handler($this->destinations())->handle('POST', '/api/print', (string) json_encode([
            'printer' => 'pc:SURFACE9|Citizen CL-S703',
            'zpl' => '^XA^XZ',
        ]));

        $this->assertSame(401, $result['status']);
        $this->assertSame([], $this->sent);
    }

    public function test_a_failing_destination_is_reported_as_an_error(): void
    {
        $failing = new class implements PrintDestinations
        {
            public function printers(): array
            {
                return [];
            }

            public function handles(string $printer): bool
            {
                return true;
            }

            public function send(string $printer, string $zpl, int $labels): array
            {
                throw new \RuntimeException('Stampante 192.168.1.50:9100 non raggiungibile');
            }
        };

        $result = $this->handler($failing)->handle('POST', '/api/print', (string) json_encode([
            'printer' => 'ip:192.168.1.50:9100',
            'zpl' => '^XA^XZ',
        ]));

        $this->assertSame(500, $result['status']);
        $this->assertSame('Stampante 192.168.1.50:9100 non raggiungibile', $result['payload']['error']);
    }

    public function test_series_zpl_keeps_copies_next_to_their_label(): void
    {
        $service = new LabelPrinterService(commandRunner: new ShellCommandRunner(static fn (): array => ['output' => [], 'code' => 0]));

        $zpl = $service->buildSeriesZpl([
            ['template' => $this->template(), 'values' => ['serial' => 'A']],
            ['template' => $this->template(), 'values' => ['serial' => 'B']],
        ], 2);

        preg_match_all('/\^FD([AB])\^FS/', $zpl, $matches);
        $this->assertSame(['A', 'A', 'B', 'B'], $matches[1]);
        $this->assertSame(3, substr_count($zpl, '^MMR'));
        $this->assertSame(1, substr_count($zpl, '^MMT'));
        $this->assertSame('', $service->buildSeriesZpl([], 3));
    }
}
