<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\ApiHandler;
use Mojito\Label\LabelPrinterService;
use Mojito\Label\ShellCommandRunner;
use Mojito\Label\TemplateRepository;
use PHPUnit\Framework\TestCase;

/**
 * Le API della stampa non-ZPL: anteprima disegnata, modalità di stampa,
 * copie e serie di etichette in una sola richiesta.
 */
final class ApiHandlerGraphicPrintTest extends TestCase
{
    private string $tempDir;

    private ApiHandler $handler;

    /** @var list<string> */
    private array $commands = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/mojito_graphic_'.uniqid('', true);
        $runner = new ShellCommandRunner(function (string $command): array {
            $this->commands[] = $command;

            return ['output' => ['ok'], 'code' => 0];
        });
        $service = new LabelPrinterService(commandRunner: $runner, printerName: 'Munbyn_ITPP941P');
        $this->handler = new ApiHandler($service, new TemplateRepository($this->tempDir));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.'/*.json') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
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
            'elements' => [
                ['type' => 'text', 'x' => 10, 'y' => 10, 'fontHeight' => 30, 'fontWidth' => 30, 'dataSource' => 'serial'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function post(string $path, array $body): array
    {
        return $this->handler->handle('POST', $path, (string) json_encode($body));
    }

    public function test_the_preview_returns_the_label_drawn_as_a_png(): void
    {
        $response = $this->post('/api/label/preview', [
            'template' => $this->template(),
            'values' => ['serial' => 'CHL12251'],
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertSame(400, $response['payload']['width']);
        $this->assertSame(200, $response['payload']['height']);
        $this->assertIsString($response['payload']['png']);
        $this->assertStringStartsWith('data:image/png;base64,', $response['payload']['png']);
        $this->assertStringStartsWith(
            "\x89PNG",
            (string) base64_decode(substr($response['payload']['png'], strlen('data:image/png;base64,')), true)
        );
    }

    public function test_the_preview_says_when_the_layout_is_missing(): void
    {
        $response = $this->post('/api/label/preview', ['values' => ['serial' => 'X']]);

        $this->assertSame(500, $response['status']);
        $this->assertStringContainsString('layout', $response['payload']['error']);
    }

    public function test_printing_in_graphic_mode_does_not_send_zpl(): void
    {
        $response = $this->post('/api/print', [
            'printMode' => 'graphic',
            'template' => $this->template(),
            'values' => ['serial' => 'CHL12251'],
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertSame('printed', $response['payload']['status']);
        $this->assertSame('graphic', $response['payload']['mode']);
        $this->assertSame(1, $response['payload']['printed']);
        $this->assertCount(1, $this->commands);
        $this->assertStringNotContainsString('-o raw', $this->commands[0]);
    }

    public function test_zpl_stays_the_default(): void
    {
        $response = $this->post('/api/print', [
            'template' => $this->template(),
            'values' => ['serial' => 'CHL12251'],
        ]);

        $this->assertSame('zpl', $response['payload']['mode']);
        $this->assertStringContainsString('-o raw', $this->commands[0]);
    }

    public function test_copies_are_printed_in_one_request(): void
    {
        $response = $this->post('/api/print', [
            'printMode' => 'graphic',
            'copies' => 3,
            'template' => $this->template(),
            'values' => ['serial' => 'CHL12251'],
        ]);

        $this->assertSame(3, $response['payload']['printed']);
        $this->assertCount(3, $this->commands);
    }

    public function test_a_series_of_labels_is_printed_in_one_request(): void
    {
        $response = $this->post('/api/print', [
            'printMode' => 'graphic',
            'template' => $this->template(),
            'values' => ['lot' => 'CHL1225'],
            'jobs' => [
                ['serial' => 'CHL12251'],
                ['serial' => 'CHL12252'],
                ['serial' => 'CHL12253'],
            ],
        ]);

        $this->assertSame(3, $response['payload']['printed']);
        $this->assertCount(3, $this->commands);
    }

    public function test_a_series_multiplies_by_the_copies(): void
    {
        $response = $this->post('/api/print', [
            'template' => $this->template(),
            'copies' => 2,
            'jobs' => [['serial' => 'A'], ['serial' => 'B']],
        ]);

        $this->assertSame(4, $response['payload']['printed']);
        $this->assertCount(4, $this->commands);
    }

    public function test_an_unreasonable_run_is_refused_before_printing_anything(): void
    {
        $jobs = array_map(static fn (int $index): array => ['serial' => (string) $index], range(1, 600));

        $response = $this->post('/api/print', [
            'template' => $this->template(),
            'copies' => 5,
            'jobs' => $jobs,
        ]);

        $this->assertSame(500, $response['status']);
        $this->assertStringContainsString('3000', $response['payload']['error']);
        $this->assertSame([], $this->commands);
    }

    public function test_jobs_must_be_a_list_of_value_maps(): void
    {
        $response = $this->post('/api/print', [
            'template' => $this->template(),
            'jobs' => ['non-un-oggetto'],
        ]);

        $this->assertSame(500, $response['status']);
        $this->assertStringContainsString('jobs', $response['payload']['error']);
    }
}
