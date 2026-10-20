<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\ApiHandler;
use Mojito\Label\LabelMedia;
use Mojito\Label\LabelPrinterService;
use Mojito\Label\PrintDestinations;
use Mojito\Label\ShellCommandRunner;
use Mojito\Label\TemplateRepository;
use PHPUnit\Framework\TestCase;

/**
 * "Ferma stampa" (#39): una serie partita per sbaglio entra tutta nella
 * stampante in pochi secondi, quindi fermarla vuol dire svuotare la coda e la
 * memoria della stampante: ~JA ("cancella tutto") per quelle ZPL.
 */
final class PrintStopTest extends TestCase
{
    /** @var list<array{command: string, file: string}> */
    private array $commands = [];

    /** @var list<string> */
    public array $stopped = [];

    private function handler(?PrintDestinations $destinations = null, int $cancelCode = 0): ApiHandler
    {
        $runner = new ShellCommandRunner(function (string $command) use ($cancelCode): array {
            // il file di stampa sparisce dopo il comando: si legge adesso
            preg_match_all("/'([^']*)'/", $command, $quoted);
            $path = (string) end($quoted[1]);
            $this->commands[] = ['command' => $command, 'file' => $path !== '' && is_file($path) ? (string) file_get_contents($path) : ''];

            return ['output' => [], 'code' => str_starts_with($command, 'cancel') ? $cancelCode : 0];
        });

        return new ApiHandler(
            new LabelPrinterService(commandRunner: $runner),
            new TemplateRepository(sys_get_temp_dir().'/mojito_stop_'.uniqid('', true)),
            $destinations,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function stop(ApiHandler $handler, array $body): array
    {
        return $handler->handle('POST', '/api/print/stop', (string) json_encode($body));
    }

    public function test_una_stampante_zpl_del_server_si_svuota_e_riceve_cancella_tutto(): void
    {
        $result = $this->stop($this->handler(), ['printer' => 'Citizen_CL_S703Z']);

        $this->assertSame(200, $result['status']);
        $this->assertSame('stopped', $result['payload']['status']);
        $this->assertSame('Citizen_CL_S703Z', $result['payload']['printer']);
        $this->assertStringContainsString('memoria della stampante azzerata', (string) $result['payload']['message']);

        $this->assertCount(2, $this->commands);
        $this->assertSame("cancel -a 'Citizen_CL_S703Z'", $this->commands[0]['command']);
        $this->assertStringStartsWith("lp -d 'Citizen_CL_S703Z' -o raw ", $this->commands[1]['command']);
        $this->assertSame(LabelPrinterService::STOP_ZPL, $this->commands[1]['file']);
        $this->assertSame('~JA', LabelPrinterService::STOP_ZPL);
    }

    public function test_una_stampante_a_immagine_si_svuota_soltanto(): void
    {
        $result = $this->stop($this->handler(), ['printer' => 'Munbyn_ITPP941P']);

        $this->assertSame('stopped', $result['payload']['status']);
        $this->assertSame([['command' => "cancel -a 'Munbyn_ITPP941P'", 'file' => '']], $this->commands);
        $this->assertStringContainsString('già nella memoria', (string) $result['payload']['message']);
    }

    public function test_una_coda_gia_vuota_non_ferma_il_cancella_tutto(): void
    {
        // cancel risponde errore se non c'e' niente da cancellare: la memoria
        // della stampante va svuotata lo stesso
        $result = $this->stop($this->handler(cancelCode: 1), ['printer' => 'Citizen_CL_S703Z']);

        $this->assertSame('stopped', $result['payload']['status']);
        $this->assertCount(2, $this->commands);
    }

    /**
     * A una stampante che non si sa se parla ZPL (una laser, per esempio) "~JA"
     * uscirebbe stampato su un foglio: si svuota solo la coda.
     */
    public function test_a_una_stampante_sconosciuta_non_si_manda_cancella_tutto(): void
    {
        $this->stop($this->handler(), ['printer' => 'Brother_MFC_L2710DW_series']);

        $this->assertSame([['command' => "cancel -a 'Brother_MFC_L2710DW_series'", 'file' => '']], $this->commands);
    }

    public function test_senza_stampante_non_ferma_niente(): void
    {
        $result = $this->stop($this->handler(), ['printer' => '  ']);

        $this->assertSame(422, $result['status']);
        $this->assertSame('Scegli la stampante da fermare.', $result['payload']['error']);
        $this->assertSame([], $this->commands);
    }

    public function test_una_destinazione_del_gestionale_si_ferma_da_sola(): void
    {
        $test = $this;
        $destinations = new class($test) implements PrintDestinations
        {
            public function __construct(private readonly PrintStopTest $test) {}

            public function printers(): array
            {
                return [];
            }

            public function handles(string $printer): bool
            {
                return str_starts_with($printer, 'pc:');
            }

            public function printMode(string $printer): ?string
            {
                return 'zpl';
            }

            public function send(string $printer, string $zpl, int $labels): array
            {
                throw new \LogicException('non usato');
            }

            public function sendImages(string $printer, array $pngs, LabelMedia $media, int $copies): array
            {
                throw new \LogicException('non usato');
            }

            public function stop(string $printer): array
            {
                $this->test->stopped[] = $printer;

                return ['status' => 'queued', 'message' => 'Stop in coda per SURFACE9'];
            }
        };

        $result = $this->stop($this->handler($destinations), ['printer' => 'pc:SURFACE9|Citizen']);

        $this->assertSame(['pc:SURFACE9|Citizen'], $this->stopped);
        $this->assertSame(['status' => 'queued', 'message' => 'Stop in coda per SURFACE9', 'printer' => 'pc:SURFACE9|Citizen'], $result['payload']);
        $this->assertSame([], $this->commands);
    }
}
