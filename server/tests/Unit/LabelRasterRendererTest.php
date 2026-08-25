<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use InvalidArgumentException;
use Mojito\Label\LabelRasterRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Il rendering grafico serve alle stampanti che non parlano ZPL: la stessa
 * etichetta disegnata nel designer viene dipinta in un PNG 1:1 in dot, che poi
 * si manda alla coda di stampa come immagine.
 */
final class LabelRasterRendererTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $values
     * @return array{image: \GdImage, width: int, height: int}
     */
    private function render(array $template, array $values = []): array
    {
        $png = (new LabelRasterRenderer)->renderPng($template, $values);
        $image = imagecreatefromstring($png);
        self::assertInstanceOf(\GdImage::class, $image);

        return ['image' => $image, 'width' => imagesx($image), 'height' => imagesy($image)];
    }

    private function isInk(\GdImage $image, int $x, int $y): bool
    {
        $rgb = imagecolorat($image, $x, $y);
        $colors = imagecolorsforindex($image, $rgb);

        return $colors['red'] < 128 && $colors['green'] < 128 && $colors['blue'] < 128;
    }

    private function inkCount(\GdImage $image): int
    {
        $count = 0;

        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                if ($this->isInk($image, $x, $y)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @param  array{image: \GdImage}  $rendered
     */
    private function inkInBox(array $rendered, int $left, int $top, int $right, int $bottom): int
    {
        $count = 0;

        for ($y = $top; $y < $bottom; $y++) {
            for ($x = $left; $x < $right; $x++) {
                if ($this->isInk($rendered['image'], $x, $y)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function test_the_image_is_exactly_the_label_size_in_dots(): void
    {
        $rendered = $this->render(['labelWidth' => 609, 'labelHeight' => 406, 'elements' => []]);

        $this->assertSame(609, $rendered['width']);
        $this->assertSame(406, $rendered['height']);
    }

    public function test_an_empty_label_is_blank(): void
    {
        $rendered = $this->render(['labelWidth' => 100, 'labelHeight' => 80, 'elements' => []]);

        $this->assertSame(0, $this->inkCount($rendered['image']));
    }

    public function test_text_is_painted_where_the_element_says(): void
    {
        $rendered = $this->render([
            'labelWidth' => 400,
            'labelHeight' => 200,
            'elements' => [
                ['type' => 'text', 'x' => 20, 'y' => 30, 'fontHeight' => 40, 'fontWidth' => 40, 'staticValue' => 'ABC'],
            ],
        ]);

        // Inchiostro dentro il riquadro del testo...
        $this->assertGreaterThan(0, $this->inkInBox($rendered, 20, 30, 220, 80));
        // ...e niente nella metà bassa dell'etichetta.
        $this->assertSame(0, $this->inkInBox($rendered, 0, 120, 400, 200));
    }

    public function test_text_uses_the_value_of_its_data_source(): void
    {
        $template = [
            'labelWidth' => 400,
            'labelHeight' => 120,
            'elements' => [
                ['type' => 'text', 'x' => 10, 'y' => 10, 'fontHeight' => 40, 'fontWidth' => 40, 'dataSource' => 'serial'],
            ],
        ];

        $empty = $this->render($template, ['serial' => '']);
        $filled = $this->render($template, ['serial' => 'CHL134BCL20S0726']);

        $this->assertSame(0, $this->inkCount($empty['image']));
        $this->assertGreaterThan(0, $this->inkCount($filled['image']));
    }

    public function test_prefix_and_suffix_are_printed_too(): void
    {
        $bare = $this->render([
            'labelWidth' => 600, 'labelHeight' => 120,
            'elements' => [['type' => 'text', 'x' => 10, 'y' => 10, 'fontHeight' => 40, 'fontWidth' => 40, 'staticValue' => 'X']],
        ]);
        $decorated = $this->render([
            'labelWidth' => 600, 'labelHeight' => 120,
            'elements' => [['type' => 'text', 'x' => 10, 'y' => 10, 'fontHeight' => 40, 'fontWidth' => 40, 'staticValue' => 'X', 'prefix' => 'Seriale: ', 'suffix' => ' ok']],
        ]);

        $this->assertGreaterThan($this->inkCount($bare['image']), $this->inkCount($decorated['image']));
    }

    public function test_a_barcode_paints_vertical_bars_of_the_declared_height(): void
    {
        $rendered = $this->render([
            'labelWidth' => 600,
            'labelHeight' => 300,
            'elements' => [
                [
                    'type' => 'barcode',
                    'barcodeType' => 'code128',
                    'x' => 20,
                    'y' => 40,
                    'moduleWidth' => 2,
                    'height' => 100,
                    'showText' => false,
                    'staticValue' => 'CHL12251',
                ],
            ],
        ]);

        // Le barre stanno nella fascia dichiarata...
        $this->assertGreaterThan(0, $this->inkInBox($rendered, 20, 40, 580, 140));
        // ...e non sotto di essa.
        $this->assertSame(0, $this->inkInBox($rendered, 0, 145, 600, 300));

        // Una colonna di barra è piena per tutta l'altezza dichiarata.
        $fullColumns = 0;
        for ($x = 20; $x < 580; $x++) {
            $filled = 0;
            for ($y = 40; $y < 140; $y++) {
                if ($this->isInk($rendered['image'], $x, $y)) {
                    $filled++;
                }
            }
            if ($filled === 100) {
                $fullColumns++;
            }
        }
        $this->assertGreaterThan(10, $fullColumns);
    }

    public function test_a_barcode_can_print_its_human_readable_line(): void
    {
        $template = [
            'labelWidth' => 600,
            'labelHeight' => 300,
            'elements' => [
                [
                    'type' => 'barcode', 'barcodeType' => 'code128', 'x' => 20, 'y' => 40,
                    'moduleWidth' => 2, 'height' => 100, 'staticValue' => 'CHL12251',
                    'showText' => true, 'textHeight' => 30,
                ],
            ],
        ];

        $rendered = $this->render($template);

        // Sotto le barre compare la riga leggibile.
        $this->assertGreaterThan(0, $this->inkInBox($rendered, 20, 142, 580, 190));
    }

    public function test_code39_is_supported(): void
    {
        $rendered = $this->render([
            'labelWidth' => 600, 'labelHeight' => 200,
            'elements' => [
                ['type' => 'barcode', 'barcodeType' => 'code39', 'x' => 10, 'y' => 10, 'moduleWidth' => 2, 'height' => 80, 'showText' => false, 'staticValue' => 'ABC123'],
            ],
        ]);

        $this->assertGreaterThan(0, $this->inkInBox($rendered, 10, 10, 590, 90));
    }

    public function test_a_qr_element_is_a_square_of_modules(): void
    {
        $rendered = $this->render([
            'labelWidth' => 400,
            'labelHeight' => 400,
            'elements' => [
                ['type' => 'qr', 'x' => 50, 'y' => 60, 'magnification' => 6, 'staticValue' => 'CHL134BCL20S07261'],
            ],
        ]);

        $this->assertGreaterThan(0, $this->inkInBox($rendered, 50, 60, 350, 360));
        // Il QR non deborda a sinistra o sopra il suo angolo.
        $this->assertSame(0, $this->inkInBox($rendered, 0, 0, 50, 400));
        $this->assertSame(0, $this->inkInBox($rendered, 0, 0, 400, 60));
    }

    public function test_an_image_element_is_drawn_in_black_and_white(): void
    {
        $source = imagecreatetruecolor(20, 20);
        $this->assertInstanceOf(\GdImage::class, $source);
        imagefill($source, 0, 0, (int) imagecolorallocate($source, 255, 255, 255));
        imagefilledrectangle($source, 5, 5, 14, 14, (int) imagecolorallocate($source, 0, 0, 0));
        ob_start();
        imagepng($source);
        $png = (string) ob_get_clean();

        $rendered = $this->render([
            'labelWidth' => 200, 'labelHeight' => 200,
            'elements' => [
                ['type' => 'image', 'x' => 40, 'y' => 40, 'width' => 40, 'height' => 40, 'threshold' => 128, 'imageData' => 'data:image/png;base64,'.base64_encode($png)],
            ],
        ]);

        $this->assertTrue($this->isInk($rendered['image'], 60, 60));
        $this->assertFalse($this->isInk($rendered['image'], 42, 42));
    }

    public function test_rotation_moves_the_text_along_the_other_axis(): void
    {
        $upright = $this->render([
            'labelWidth' => 300, 'labelHeight' => 300,
            'elements' => [['type' => 'text', 'x' => 10, 'y' => 10, 'fontHeight' => 30, 'fontWidth' => 30, 'staticValue' => 'ABCDEFGH']],
        ]);
        $rotated = $this->render([
            'labelWidth' => 300, 'labelHeight' => 300,
            'elements' => [['type' => 'text', 'x' => 10, 'y' => 10, 'fontHeight' => 30, 'fontWidth' => 30, 'rotation' => 90, 'staticValue' => 'ABCDEFGH']],
        ]);

        // In piedi il testo corre verso destra e non scende mai in basso.
        $this->assertGreaterThan(0, $this->inkInBox($upright, 120, 0, 300, 120));
        $this->assertSame(0, $this->inkInBox($upright, 0, 120, 300, 300));

        // Ruotato di 90° scende invece verso il basso, restando a sinistra.
        $this->assertGreaterThan(0, $this->inkInBox($rotated, 0, 120, 300, 300));
        $this->assertSame(0, $this->inkInBox($rotated, 120, 0, 300, 300));
    }

    public function test_it_refuses_a_label_that_is_not_a_sensible_size(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new LabelRasterRenderer)->renderPng(['labelWidth' => 0, 'labelHeight' => 0, 'elements' => []], []);
    }

    public function test_it_refuses_an_absurdly_large_label(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new LabelRasterRenderer)->renderPng(['labelWidth' => 40000, 'labelHeight' => 40000, 'elements' => []], []);
    }

    public function test_unknown_element_types_are_ignored(): void
    {
        $rendered = $this->render([
            'labelWidth' => 100, 'labelHeight' => 100,
            'elements' => [['type' => 'hologram', 'x' => 0, 'y' => 0], 'not-an-element'],
        ]);

        $this->assertSame(0, $this->inkCount($rendered['image']));
    }
}
