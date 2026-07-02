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

    public function test_render_template_with_origin_offset(): void
    {
        $zpl = $this->builder->renderTemplate([
            'labelWidth' => 591,
            'labelHeight' => 591,
            'originX' => 24,
            'originY' => 8,
            'elements' => [],
        ]);

        $this->assertStringContainsString('^LH24,8', $zpl);
    }

    public function test_render_template_omits_origin_when_zero_or_negative(): void
    {
        $zpl = $this->builder->renderTemplate([
            'labelWidth' => 591,
            'labelHeight' => 591,
            'originX' => 0,
            'originY' => -5,
            'elements' => [],
        ]);

        $this->assertStringNotContainsString('^LH', $zpl);
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

    public function test_render_text_bold_uses_double_strike(): void
    {
        $zpl = $this->builder->renderTemplate([
            'elements' => [
                ['type' => 'text', 'x' => 10, 'y' => 20, 'staticValue' => 'BOLD', 'bold' => true],
            ],
        ]);

        $this->assertSame(2, substr_count($zpl, 'BOLD'));
        $this->assertStringContainsString('^FO10,20^', $zpl);
        $this->assertStringContainsString('^FO11,20^', $zpl);
    }

    public function test_render_text_underline_draws_gb_line(): void
    {
        $zpl = $this->builder->renderTemplate([
            'elements' => [
                ['type' => 'text', 'x' => 10, 'y' => 20, 'staticValue' => 'LINE', 'fontHeight' => 30, 'underline' => true],
            ],
        ]);

        $this->assertStringContainsString('^GB', $zpl);
        $this->assertStringContainsString('LINE', $zpl);
    }

    public function test_render_qr_with_defaults(): void
    {
        $zpl = $this->builder->renderTemplate([
            'elements' => [
                ['type' => 'qr', 'x' => 10, 'y' => 20, 'dataSource' => 'passport'],
            ],
        ], ['passport' => 'PACK-42']);

        $this->assertStringContainsString('^FO10,20^BQN,2,4^FDMA,PACK-42^FS', $zpl);
    }

    public function test_render_qr_with_custom_magnification_and_error_correction(): void
    {
        $zpl = $this->builder->renderTemplate([
            'elements' => [
                [
                    'type' => 'qr',
                    'x' => 0,
                    'y' => 0,
                    'staticValue' => 'ABC',
                    'magnification' => 7,
                    'errorCorrection' => 'H',
                ],
            ],
        ]);

        $this->assertStringContainsString('^BQN,2,7^FDHA,ABC^FS', $zpl);
    }

    public function test_render_qr_clamps_magnification_to_valid_range(): void
    {
        $tooLow = $this->builder->renderTemplate([
            'elements' => [['type' => 'qr', 'x' => 0, 'y' => 0, 'staticValue' => 'X', 'magnification' => 0]],
        ]);
        $tooHigh = $this->builder->renderTemplate([
            'elements' => [['type' => 'qr', 'x' => 0, 'y' => 0, 'staticValue' => 'X', 'magnification' => 99]],
        ]);

        $this->assertStringContainsString('^BQN,2,1^FD', $tooLow);
        $this->assertStringContainsString('^BQN,2,10^FD', $tooHigh);
    }

    public function test_render_qr_falls_back_to_medium_error_correction_for_invalid_values(): void
    {
        $zpl = $this->builder->renderTemplate([
            'elements' => [
                ['type' => 'qr', 'x' => 0, 'y' => 0, 'staticValue' => 'X', 'errorCorrection' => 'Z'],
            ],
        ]);

        $this->assertStringContainsString('^FDMA,X^FS', $zpl);
    }

    public function test_render_qr_escapes_special_chars(): void
    {
        $zpl = $this->builder->renderTemplate([
            'elements' => [
                ['type' => 'qr', 'x' => 0, 'y' => 0, 'staticValue' => 'A^B~C'],
            ],
        ]);

        $this->assertStringContainsString('\5E', $zpl);
        $this->assertStringContainsString('\7E', $zpl);
    }
}
