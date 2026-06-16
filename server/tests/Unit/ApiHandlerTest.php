<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\ApiHandler;
use Mojito\Label\LabelPrinterService;
use Mojito\Label\ShellCommandRunner;
use Mojito\Label\TemplateRepository;
use PHPUnit\Framework\TestCase;

final class ApiHandlerTest extends TestCase
{
    private string $tempDir;

    private ApiHandler $handler;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/mojito_api_'.uniqid('', true);
        $runner = new ShellCommandRunner(static fn (): array => [
            'output' => ['printer Citizen_CL_S703Z'],
            'code' => 0,
        ]);
        $service = new LabelPrinterService(commandRunner: $runner);
        $this->handler = new ApiHandler($service, new TemplateRepository($this->tempDir));
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir.'/*.json') ?: [];

        foreach ($files as $file) {
            unlink($file);
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function test_health_and_default_template(): void
    {
        $health = $this->handler->handle('GET', '/api/health');
        $this->assertSame(200, $health['status']);
        $this->assertSame('ok', $health['payload']['status']);

        $default = $this->handler->handle('GET', '/api/template/default');
        $this->assertSame('cavallini-service', $default['payload']['id']);
    }

    public function test_template_crud_and_print_flow(): void
    {
        $save = $this->handler->handle('POST', '/api/templates', json_encode([
            'id' => 'api-layout',
            'name' => 'API Layout',
            'elements' => [
                ['type' => 'text', 'x' => 0, 'y' => 0, 'dataSource' => 'title'],
            ],
            'dataSources' => [
                ['name' => 'title', 'label' => 'Titolo'],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('saved', $save['payload']['status']);

        $list = $this->handler->handle('GET', '/api/templates');
        $this->assertCount(1, $list['payload']['templates']);

        $preview = $this->handler->handle('POST', '/api/zpl/preview', json_encode([
            'templateId' => 'api-layout',
            'values' => ['title' => 'Via API'],
        ], JSON_THROW_ON_ERROR));

        $this->assertStringContainsString('Via API', $preview['payload']['zpl']);

        $print = $this->handler->handle('POST', '/api/print', json_encode([
            'zpl' => '^XA^XZ',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('printed', $print['payload']['status']);

        $delete = $this->handler->handle('DELETE', '/api/templates/api-layout');
        $this->assertSame('deleted', $delete['payload']['status']);
    }

    public function test_get_template_by_id(): void
    {
        $repository = new TemplateRepository($this->tempDir);
        $repository->save([
            'id' => 'load-me',
            'name' => 'Load me',
            'elements' => [],
            'dataSources' => [],
        ]);

        $result = $this->handler->handle('GET', '/api/templates/load-me');
        $this->assertSame('load-me', $result['payload']['id']);
    }

    public function test_not_found_and_invalid_json(): void
    {
        $missing = $this->handler->handle('GET', '/api/missing');
        $this->assertSame(404, $missing['status']);

        $badJson = $this->handler->handle('POST', '/api/print', '{');
        $this->assertSame(500, $badJson['status']);
    }

    public function test_find_missing_template_returns_error(): void
    {
        $result = $this->handler->handle('GET', '/api/templates/missing-id');
        $this->assertSame(500, $result['status']);
        $this->assertStringContainsString('non trovato', $result['payload']['error']);
    }

    public function test_save_invalid_template_returns_error(): void
    {
        $result = $this->handler->handle('POST', '/api/templates', json_encode(['name' => 'bad'], JSON_THROW_ON_ERROR));
        $this->assertSame(500, $result['status']);
        $this->assertStringContainsString('elements', $result['payload']['error']);
    }

    public function test_print_failure_returns_error(): void
    {
        $runner = new ShellCommandRunner(static fn (): array => ['output' => ['fail'], 'code' => 1]);
        $handler = new ApiHandler(new LabelPrinterService(commandRunner: $runner), new TemplateRepository($this->tempDir));

        $result = $handler->handle('POST', '/api/print', json_encode(['title' => 'X'], JSON_THROW_ON_ERROR));
        $this->assertSame(500, $result['status']);
        $this->assertStringContainsString('Errore stampa', $result['payload']['error']);
    }

    public function test_list_printers_fallback(): void
    {
        $runner = new ShellCommandRunner(static fn (): array => ['output' => [], 'code' => 1]);
        $handler = new ApiHandler(new LabelPrinterService(commandRunner: $runner), new TemplateRepository($this->tempDir));

        $result = $handler->handle('GET', '/api/printers');
        $this->assertSame(['Citizen_CL_S703Z'], $result['payload']['printers']);
    }
}
