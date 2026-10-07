<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\ApiHandler;
use Mojito\Label\LabelMedia;
use Mojito\Label\LabelPrinterService;
use Mojito\Label\PrintDestinations;
use Mojito\Label\PrinterPrintMode;
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

    /** @var list<array{printer: string, pngs: list<string>, media: LabelMedia, copies: int}> */
    private array $images = [];

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
                    ['value' => 'pc:UFFICIO|Munbyn ITPP941P', 'label' => 'UFFICIO · Munbyn ITPP941P'],
                    ['value' => 'pc:UFFICIO|Brother MFC', 'label' => 'UFFICIO · Brother MFC'],
                ];
            }

            public function handles(string $printer): bool
            {
                return str_starts_with($printer, 'ip:') || str_starts_with($printer, 'pc:');
            }

            public function printMode(string $printer): ?string
            {
                return str_starts_with($printer, 'ip:')
                    ? 'zpl'
                    : PrinterPrintMode::forPrinter((string) substr($printer, (int) strpos($printer, '|') + 1));
            }

            public function send(string $printer, string $zpl, int $labels): array
            {
                $this->test->record($printer, $zpl, $labels);

                return ['status' => str_starts_with($printer, 'pc:') ? 'queued' : 'printed'];
            }

            public function sendImages(string $printer, array $pngs, LabelMedia $media, int $copies): array
            {
                $this->test->recordImages($printer, $pngs, $media, $copies);

                return ['status' => 'queued'];
            }
        };
    }

    public function record(string $printer, string $zpl, int $labels): void
    {
        $this->sent[] = ['printer' => $printer, 'zpl' => $zpl, 'labels' => $labels];
    }

    /**
     * @param  list<string>  $pngs
     */
    public function recordImages(string $printer, array $pngs, LabelMedia $media, int $copies): void
    {
        $this->images[] = ['printer' => $printer, 'pngs' => $pngs, 'media' => $media, 'copies' => $copies];
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
        $this->assertSame(['Citizen_CL_S703Z', 'ip:192.168.1.50:9100', 'pc:SURFACE9|Citizen CL-S703', 'pc:UFFICIO|Munbyn ITPP941P', 'pc:UFFICIO|Brother MFC'], $payload['printers']);
        $this->assertSame('SURFACE9 · Citizen CL-S703', $payload['printerLabels']['pc:SURFACE9|Citizen CL-S703']);
        // Ogni destinazione dice come va stampata: il designer imposta il layout di conseguenza.
        $this->assertSame('zpl', $payload['printerModes']['ip:192.168.1.50:9100']);
        $this->assertSame('zpl', $payload['printerModes']['pc:SURFACE9|Citizen CL-S703']);
        $this->assertSame('graphic', $payload['printerModes']['pc:UFFICIO|Munbyn ITPP941P']);
        // Un modello che non si riconosce non si inventa: il layout tiene il suo.
        $this->assertArrayNotHasKey('pc:UFFICIO|Brother MFC', $payload['printerModes']);
    }

    /**
     * Una Munbyn lo ZPL non lo capisce: a una destinazione che stampa a
     * immagine si consegnano le etichette gia' disegnate, una per etichetta,
     * con la misura e le copie.
     */
    public function test_a_series_for_an_image_destination_goes_as_drawn_labels(): void
    {
        $result = $this->handler($this->destinations())->handle('POST', '/api/print', (string) json_encode([
            'printer' => 'pc:UFFICIO|Munbyn ITPP941P',
            'template' => $this->template(),
            'jobs' => [['serial' => 'LOT1'], ['serial' => 'LOT2']],
            'copies' => 2,
        ]));

        $this->assertSame(200, $result['status']);
        $this->assertSame('queued', $result['payload']['status']);
        $this->assertSame('graphic', $result['payload']['mode']);
        $this->assertSame(4, $result['payload']['printed']);
        $this->assertSame([], $this->sent);

        $this->assertCount(1, $this->images);
        $this->assertSame('pc:UFFICIO|Munbyn ITPP941P', $this->images[0]['printer']);
        $this->assertSame(2, $this->images[0]['copies']);
        $this->assertCount(2, $this->images[0]['pngs']);
        $this->assertNotSame($this->images[0]['pngs'][0], $this->images[0]['pngs'][1]);
        foreach ($this->images[0]['pngs'] as $png) {
            $this->assertStringStartsWith("\x89PNG", $png);
        }
        $this->assertSame(400, $this->images[0]['media']->widthDots());
        $this->assertSame(200, $this->images[0]['media']->heightDots());
    }

    /** Uno ZPL scritto a mano resta ZPL, qualunque sia la stampante. */
    public function test_raw_zpl_to_an_image_destination_stays_zpl(): void
    {
        $this->handler($this->destinations())->handle('POST', '/api/print', (string) json_encode([
            'printer' => 'pc:UFFICIO|Munbyn ITPP941P',
            'zpl' => '^XA^FDRAW^FS^XZ',
        ]));

        $this->assertSame('^XA^FDRAW^FS^XZ', $this->sent[0]['zpl']);
        $this->assertSame([], $this->images);
    }

    /** Il modello della destinazione conta piu' del layout e della richiesta. */
    public function test_a_zpl_destination_wins_over_a_graphic_layout(): void
    {
        $result = $this->handler($this->destinations())->handle('POST', '/api/print', (string) json_encode([
            'printer' => 'pc:SURFACE9|Citizen CL-S703',
            'printMode' => 'graphic',
            'template' => ['printMode' => 'graphic'] + $this->template(),
            'values' => ['serial' => 'LOT1'],
        ]));

        $this->assertSame('zpl', $result['payload']['mode']);
        $this->assertCount(1, $this->sent);
        $this->assertSame([], $this->images);
    }

    /** Su una stampante che non si riconosce decidono la richiesta o il layout. */
    public function test_an_unknown_destination_follows_the_request_or_the_layout(): void
    {
        $handler = $this->handler($this->destinations());

        $handler->handle('POST', '/api/print', (string) json_encode([
            'printer' => 'pc:UFFICIO|Brother MFC',
            'template' => ['printMode' => 'graphic'] + $this->template(),
            'values' => ['serial' => 'LOT1'],
        ]));
        $handler->handle('POST', '/api/print', (string) json_encode([
            'printer' => 'pc:UFFICIO|Brother MFC',
            'printMode' => 'zpl',
            'template' => ['printMode' => 'graphic'] + $this->template(),
            'values' => ['serial' => 'LOT1'],
        ]));
        $handler->handle('POST', '/api/print', (string) json_encode([
            'printer' => 'pc:UFFICIO|Brother MFC',
            'template' => $this->template(),
            'values' => ['serial' => 'LOT1'],
        ]));

        $this->assertCount(1, $this->images);
        $this->assertCount(1, $this->images[0]['pngs']);
        $this->assertSame(1, $this->images[0]['copies']);
        $this->assertCount(2, $this->sent);
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
            'jobs' => [['serial' => 'LOT1'], ['serial' => 'LOT2']],
            'copies' => 2,
        ]));

        $this->assertSame(200, $result['status']);
        $this->assertSame('queued', $result['payload']['status']);
        $this->assertSame(4, $result['payload']['printed']);
        $this->assertSame('zpl', $result['payload']['mode']);
        $this->assertSame('pc:SURFACE9|Citizen CL-S703', $result['payload']['printer']);

        $this->assertCount(1, $this->sent);
        preg_match_all('/\^FD(LOT\d)\^FS.*?\^PQ(\d+)\^XZ/s', $this->sent[0]['zpl'], $matches);
        $this->assertSame(['LOT1', 'LOT2'], $matches[1]);
        $this->assertSame(['2', '2'], $matches[2]);
        $this->assertSame(4, $this->sent[0]['labels']);

        // Il server non ha stampato niente di suo.
        $this->assertSame([], array_values(array_filter($this->commands, static fn (string $c): bool => str_starts_with($c, 'lp '))));
    }

    /**
     * Anche un layout disegnato per una stampante a immagine va in ZPL a una
     * stampante di rete: riceve solo ZPL.
     */
    public function test_a_graphic_layout_is_sent_as_zpl_to_those_destinations(): void
    {
        $result = $this->handler($this->destinations())->handle('POST', '/api/print', (string) json_encode([
            'printer' => 'ip:192.168.1.50:9100',
            'printMode' => 'graphic',
            'template' => $this->template(),
            'values' => ['serial' => 'LOT9'],
        ]));

        $this->assertSame('printed', $result['payload']['status']);
        $this->assertStringStartsWith('^XA', $this->sent[0]['zpl']);
        $this->assertStringContainsString('^FDLOT9^FS', $this->sent[0]['zpl']);
    }

    public function test_raw_zpl_with_copies_goes_to_the_destination_in_one_piece(): void
    {
        $this->handler($this->destinations())->handle('POST', '/api/print', (string) json_encode([
            'printer' => 'ip:192.168.1.50:9100',
            'zpl' => '^XA^FDRAW^FS^XZ',
            'copies' => 3,
        ]));

        $this->assertSame('^XA^FDRAW^FS^PQ3^XZ', $this->sent[0]['zpl']);
        $this->assertSame(3, $this->sent[0]['labels']);
    }

    public function test_a_server_printer_is_still_printed_by_the_server(): void
    {
        $result = $this->handler($this->destinations())->handle('POST', '/api/print', (string) json_encode([
            'printer' => 'Citizen_CL_S703Z',
            'template' => $this->template(),
            'values' => ['serial' => 'LOT1'],
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

            public function printMode(string $printer): ?string
            {
                return null;
            }

            public function sendImages(string $printer, array $pngs, LabelMedia $media, int $copies): array
            {
                throw new \LogicException('non usato');
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

        preg_match_all('/\^FD([AB])\^FS.*?\^PQ(\d+)\^XZ/s', $zpl, $matches);
        $this->assertSame(['A', 'B'], $matches[1]);
        $this->assertSame(['2', '2'], $matches[2]);
        $this->assertStringNotContainsString('^MM', $zpl);
        $this->assertSame('', $service->buildSeriesZpl([], 3));
    }
}
