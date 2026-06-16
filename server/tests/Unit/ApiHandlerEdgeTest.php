<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\ApiHandler;
use Mojito\Label\LabelPrinterService;
use Mojito\Label\ShellCommandRunner;
use Mojito\Label\TemplateRepository;
use PHPUnit\Framework\TestCase;

final class ApiHandlerEdgeTest extends TestCase
{
    private string $tempDir;

    private ApiHandler $handler;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/mojito_edge_'.uniqid('', true);
        $runner = new ShellCommandRunner(static fn (): array => ['output' => [], 'code' => 0]);
        $this->handler = new ApiHandler(
            new LabelPrinterService(commandRunner: $runner),
            new TemplateRepository($this->tempDir),
        );
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

    public function test_decode_body_whitespace_only(): void
    {
        $result = $this->handler->handle('POST', '/api/zpl/preview', "  \n\t  ");
        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('zpl', $result['payload']);
    }

    public function test_preview_and_print_with_template_id(): void
    {
        $this->handler->handle('POST', '/api/templates', json_encode([
            'id' => 'edge-layout',
            'name' => 'Edge',
            'elements' => [
                ['type' => 'text', 'x' => 0, 'y' => 0, 'dataSource' => 'title'],
            ],
            'dataSources' => [
                ['name' => 'title', 'label' => 'Titolo'],
            ],
        ], JSON_THROW_ON_ERROR));

        $preview = $this->handler->handle('POST', '/api/zpl/preview', json_encode([
            'templateId' => 'edge-layout',
            'values' => ['title' => 'Da templateId'],
            'printer' => 'Custom_Printer',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(200, $preview['status']);
        $this->assertStringContainsString('Da templateId', $preview['payload']['zpl']);
        $this->assertSame('Custom_Printer', $preview['payload']['printer']);

        $print = $this->handler->handle('POST', '/api/print', json_encode([
            'templateId' => 'edge-layout',
            'values' => ['title' => 'Stampa'],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('printed', $print['payload']['status']);
    }

    public function test_print_with_explicit_zpl(): void
    {
        $result = $this->handler->handle('POST', '/api/print', json_encode([
            'zpl' => '^XA^FO10,10^FDZPL^FS^XZ',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('printed', $result['payload']['status']);
    }

    public function test_template_routes_reject_invalid_paths(): void
    {
        $nested = $this->handler->handle('GET', '/api/templates/id/extra');
        $this->assertSame(404, $nested['status']);

        $wrongMethod = $this->handler->handle('POST', '/api/templates/my-id');
        $this->assertSame(404, $wrongMethod['status']);

        $deleteWrong = $this->handler->handle('DELETE', '/api/templates/');
        $this->assertSame(404, $deleteWrong['status']);
    }

    public function test_decode_body_rejects_scalar_json(): void
    {
        $result = $this->handler->handle('POST', '/api/print', '"just-a-string"');
        $this->assertSame(500, $result['status']);
        $this->assertStringContainsString('JSON non valido', $result['payload']['error']);
    }

    public function test_resolve_print_payload_ignores_empty_template_id(): void
    {
        $result = $this->handler->handle('POST', '/api/print', json_encode([
            'templateId' => '',
            'title' => 'Fallback',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('printed', $result['payload']['status']);
    }

    public function test_default_template_structure(): void
    {
        $template = $this->handler->defaultTemplate();

        $this->assertSame('cavallini-service', $template['id']);
        $this->assertSame(600, $template['labelWidth']);
        $this->assertCount(4, $template['dataSources']);
        $this->assertCount(4, $template['elements']);
    }
}
