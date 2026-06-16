<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\ApiHandler;
use Mojito\Label\LabelPrinterService;
use Mojito\Label\ShellCommandRunner;
use Mojito\Label\TemplateRepository;
use Mojito\Label\ZplBuilder;
use Mojito\Label\ZplImageConverter;
use PHPUnit\Framework\TestCase;

final class CoverageEdgeCasesTest extends TestCase
{
    public function test_api_handler_preview_with_empty_body_and_empty_printer(): void
    {
        $tempDir = sys_get_temp_dir().'/mojito_cov_'.uniqid('', true);
        $handler = new ApiHandler(
            new LabelPrinterService(commandRunner: new ShellCommandRunner(static fn (): array => ['output' => [], 'code' => 0])),
            new TemplateRepository($tempDir)
        );

        $emptyPreview = $handler->handle('POST', '/api/zpl/preview', '');
        $this->assertSame(200, $emptyPreview['status']);

        $explicitPrinter = $handler->handle('POST', '/api/zpl/preview', json_encode([
            'title' => 'X',
            'printer' => '',
        ], JSON_THROW_ON_ERROR));
        $this->assertSame(200, $explicitPrinter['status']);

        rmdir($tempDir);
    }

    public function test_zpl_builder_raw_base64_image_and_element_default_value(): void
    {
        $builder = new ZplBuilder;
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        $zpl = $builder->renderTemplate([
            'elements' => [
                [
                    'type' => 'image',
                    'x' => 0,
                    'y' => 0,
                    'imageData' => base64_encode($png ?: ''),
                ],
                [
                    'type' => 'text',
                    'x' => 0,
                    'y' => 10,
                    'dataSource' => 'title',
                    'defaultValue' => 'DEF',
                ],
                [
                    'type' => 'unknown',
                    'x' => 0,
                    'y' => 20,
                ],
            ],
        ]);

        $this->assertStringContainsString('^GFA', $zpl);
        $this->assertStringContainsString('DEF', $zpl);
    }

    public function test_zpl_image_converter_resize_and_normalize_hex(): void
    {
        $converter = new ZplImageConverter;
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        $this->assertNotFalse($png);

        $resized = $converter->fromBinary($png ?: '', 2, 2);
        $this->assertIsArray($resized);
        $this->assertSame(2, $resized['totalBytes']);
    }

    public function test_template_repository_list_ignores_unreadable_files(): void
    {
        $tempDir = sys_get_temp_dir().'/mojito_repo_'.uniqid('', true);
        mkdir($tempDir);
        mkdir($tempDir.'/blocked.json');
        file_put_contents($tempDir.'/blocked.json/inside', '{}');

        $repository = new TemplateRepository($tempDir);
        $repository->save([
            'id' => 'valid',
            'name' => 'Valid',
            'elements' => [],
            'dataSources' => [],
        ]);

        $this->assertCount(1, $repository->list());

        unlink($tempDir.'/blocked.json/inside');
        rmdir($tempDir.'/blocked.json');
        unlink($tempDir.'/valid.json');
        rmdir($tempDir);
    }
}
