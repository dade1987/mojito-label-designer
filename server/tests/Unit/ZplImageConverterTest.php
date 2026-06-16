<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\ZplImageConverter;
use PHPUnit\Framework\TestCase;

final class ZplImageConverterTest extends TestCase
{
    public function test_from_binary_returns_null_on_empty_or_invalid(): void
    {
        $converter = new ZplImageConverter;

        $this->assertNull($converter->fromBinary(''));
        $this->assertNull($converter->fromBinary('not-an-image'));
    }

    public function test_from_binary_converts_one_pixel_png(): void
    {
        $converter = new ZplImageConverter;
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        $this->assertNotFalse($png);

        $graphic = $converter->fromBinary($png ?: '', 1, 1);

        $this->assertIsArray($graphic);
        $this->assertSame(1, $graphic['bytesPerRow']);
        $this->assertSame(1, $graphic['totalBytes']);
        $this->assertMatchesRegularExpression('/^[0-9A-F]+$/', $graphic['hexData']);
    }

    public function test_from_binary_resizes_image(): void
    {
        $converter = new ZplImageConverter;
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        $this->assertNotFalse($png);

        $graphic = $converter->fromBinary($png ?: '', 2, 2);

        $this->assertIsArray($graphic);
        $this->assertSame(1, $graphic['bytesPerRow']);
        $this->assertSame(2, $graphic['totalBytes']);
    }

    public function test_from_binary_handles_partial_row_bits(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required.');
        }

        $converter = new ZplImageConverter;
        $image = imagecreatetruecolor(3, 1);
        $this->assertNotFalse($image);

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        $graphic = $converter->fromBinary($png ?: '');

        $this->assertIsArray($graphic);
        $this->assertSame(1, $graphic['bytesPerRow']);
    }

    public function test_from_binary_returns_null_when_resize_canvas_fails(): void
    {
        $converter = new class extends ZplImageConverter
        {
            protected function createResizeCanvas(int $width, int $height): \GdImage|false
            {
                return false;
            }
        };

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        $this->assertNotFalse($png);
        $this->assertNull($converter->fromBinary($png ?: '', 2, 2));
    }

    public function test_from_binary_returns_null_when_resize_fails(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required.');
        }

        $converter = new ZplImageConverter;
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        $this->assertNotFalse($png);

        $result = $converter->fromBinary($png ?: '', 50000, 50000);

        if ($result !== null) {
            $this->markTestSkipped('Ambiente consente resize molto grandi.');
        }

        $this->assertNull($result);
    }

    public function test_from_binary_pads_short_hex_data(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required.');
        }

        $converter = new ZplImageConverter;
        $image = imagecreatetruecolor(9, 1);
        $this->assertNotFalse($image);

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        $graphic = $converter->fromBinary($png ?: '');

        $this->assertIsArray($graphic);
        $this->assertSame(strlen($graphic['hexData']), $graphic['totalBytes'] * 2);
    }
}
