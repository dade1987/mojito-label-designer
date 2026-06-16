<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use InvalidArgumentException;
use Mojito\Label\ZplBuilder;
use PHPUnit\Framework\TestCase;

final class ZplBuilderTest extends TestCase
{
    private ZplBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ZplBuilder;
    }

    public function test_render_default_label_escapes_special_chars(): void
    {
        $zpl = $this->builder->renderDefaultLabel([
            'title' => 'Test^tilde~',
            'product' => 'P',
            'serial' => 'S',
            'barcode' => '123',
        ]);

        $this->assertStringContainsString('\5E', $zpl);
        $this->assertStringContainsString('\7E', $zpl);
    }

    public function test_render_template_with_all_element_types(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        $zpl = $this->builder->renderTemplate([
            'labelWidth' => 500,
            'labelHeight' => 300,
            'elements' => [
                ['type' => 'text', 'x' => 1, 'y' => 2, 'dataSource' => 'title', 'prefix' => 'T: '],
                ['type' => 'barcode', 'x' => 3, 'y' => 4, 'dataSource' => 'barcode', 'barcodeType' => 'code39', 'showText' => false],
                [
                    'type' => 'image',
                    'x' => 5,
                    'y' => 6,
                    'width' => 1,
                    'height' => 1,
                    'imageData' => 'data:image/png;base64,'.base64_encode($png ?: ''),
                ],
                ['type' => 'unknown'],
                'invalid',
            ],
        ], [
            'title' => 'MOJITO',
            'barcode' => 'ABC',
        ]);

        $this->assertStringContainsString('^PW500', $zpl);
        $this->assertStringContainsString('T: MOJITO', $zpl);
        $this->assertStringContainsString('^B3N', $zpl);
        $this->assertStringContainsString('^GFA', $zpl);
    }

    public function test_render_template_throws_on_invalid_elements(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder->renderTemplate(['elements' => 'nope']);
    }

    public function test_render_image_skips_invalid_data(): void
    {
        $zpl = $this->builder->renderTemplate([
            'elements' => [
                ['type' => 'image', 'x' => 0, 'y' => 0, 'imageData' => ''],
                ['type' => 'image', 'x' => 0, 'y' => 0, 'imageData' => 'data:broken'],
                ['type' => 'image', 'x' => 0, 'y' => 0, 'imageData' => 'data:image/png;base64,!!!'],
                ['type' => 'image', 'x' => 0, 'y' => 0, 'imageData' => base64_encode('not-an-image')],
            ],
        ]);

        $this->assertStringNotContainsString('^GFA', $zpl);
    }

    public function test_render_template_without_label_dimensions(): void
    {
        $zpl = $this->builder->renderTemplate([
            'labelWidth' => 0,
            'labelHeight' => 0,
            'elements' => [
                ['type' => 'text', 'x' => 0, 'y' => 0, 'staticValue' => 'NO SIZE'],
            ],
        ]);

        $this->assertStringNotContainsString('^PW', $zpl);
        $this->assertStringContainsString('NO SIZE', $zpl);
    }

    public function test_render_barcode_with_show_text_disabled(): void
    {
        $zpl = $this->builder->renderTemplate([
            'elements' => [
                [
                    'type' => 'barcode',
                    'x' => 0,
                    'y' => 0,
                    'dataSource' => 'code',
                    'barcodeType' => 'code128',
                    'showText' => false,
                ],
            ],
        ], ['code' => '123']);

        $this->assertStringContainsString(',N,N^FD123^FS', $zpl);
    }

    public function test_resolve_static_and_default_values(): void
    {
        $zpl = $this->builder->renderTemplate([
            'elements' => [
                ['type' => 'text', 'x' => 0, 'y' => 0, 'staticValue' => 'STATIC'],
                ['type' => 'text', 'x' => 0, 'y' => 10, 'dataSource' => 'missing', 'defaultValue' => 'DEF'],
            ],
        ]);

        $this->assertStringContainsString('STATIC', $zpl);
        $this->assertStringContainsString('DEF', $zpl);
    }
}
