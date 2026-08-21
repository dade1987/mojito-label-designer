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

    public function test_palette_png_reads_real_colors_not_indexes(): void
    {
        if (! function_exists('imagecreate')) {
            $this->markTestSkipped('GD extension required.');
        }

        // PNG a palette tutto bianco: indice 0 = bianco. Letto come indice
        // (0 = nero) stamperebbe un blocco pieno; letto come colore resta
        // vuoto com'e' davvero.
        $image = imagecreate(8, 2);
        $this->assertNotFalse($image);
        imagecolorallocate($image, 255, 255, 255);

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        $converter = new ZplImageConverter;
        $graphic = $converter->fromBinary($png ?: '');

        $this->assertIsArray($graphic);
        $this->assertSame('0000', $graphic['hexData']);
    }

    public function test_resize_keeps_aspect_ratio_like_preview(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required.');
        }

        // Immagine 16x8 tutta nera dentro un riquadro 16x16: come l'anteprima
        // (object-fit: contain) deve restare 16x8 centrata, non stirarsi.
        // Le righe sopra e sotto restano quindi vuote.
        $image = imagecreatetruecolor(16, 8);
        $this->assertNotFalse($image);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, 15, 7, (int) $black);

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        $converter = new ZplImageConverter;
        $graphic = $converter->fromBinary($png ?: '', 16, 16);

        $this->assertIsArray($graphic);
        $this->assertSame(2, $graphic['bytesPerRow']);
        $this->assertSame(32, $graphic['totalBytes']);

        $rows = str_split($graphic['hexData'], 4);
        $this->assertSame('0000', $rows[0]);
        $this->assertSame('FFFF', $rows[7]);
        $this->assertSame('FFFF', $rows[8]);
        $this->assertSame('0000', $rows[15]);
    }

    public function test_rotation_swaps_dimensions(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required.');
        }

        // 16x8 ruotata di 90 gradi diventa 8x16: una riga passa da 2 byte a 1.
        $image = imagecreatetruecolor(16, 8);
        $this->assertNotFalse($image);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, 15, 7, (int) $black);

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        $converter = new ZplImageConverter;
        $graphic = $converter->fromBinary($png ?: '', 0, 0, 128, 90);

        $this->assertIsArray($graphic);
        $this->assertSame(1, $graphic['bytesPerRow']);
        $this->assertSame(16, $graphic['totalBytes']);
        $this->assertSame(str_repeat('FF', 16), $graphic['hexData']);
    }

    public function test_rotation_180_keeps_dimensions(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required.');
        }

        // 8x2: riga nera sopra, riga bianca sotto. Capovolta, il nero finisce sotto.
        $image = imagecreatetruecolor(8, 2);
        $this->assertNotFalse($image);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, 7, 1, (int) $white);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, 7, 0, (int) $black);

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        $converter = new ZplImageConverter;
        $graphic = $converter->fromBinary($png ?: '', 0, 0, 128, 180);

        $this->assertIsArray($graphic);
        $this->assertSame(1, $graphic['bytesPerRow']);
        $this->assertSame('00FF', $graphic['hexData']);
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
