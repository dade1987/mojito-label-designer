<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\LabelMedia;
use Mojito\Label\PrinterPlatform;
use PHPUnit\Framework\TestCase;

/**
 * I comandi per mandare in stampa un'etichetta già disegnata (PNG), cioè la
 * strada delle stampanti che non parlano ZPL.
 */
final class PrinterPlatformGraphicTest extends TestCase
{
    public function test_a_label_in_dots_becomes_a_paper_size_in_millimetres(): void
    {
        // 609 x 406 dot a 203 dpi = l'etichetta 76 x 51 mm della postazione.
        $media = new LabelMedia(609, 406, 203);

        $this->assertSame(76.2, round($media->widthMillimetres(), 1));
        $this->assertSame(50.8, round($media->heightMillimetres(), 1));
        $this->assertSame('Custom.76x51mm', $media->cupsMediaName());
    }

    public function test_a_missing_or_silly_resolution_falls_back_to_203_dpi(): void
    {
        $this->assertSame(203, (new LabelMedia(600, 400, 0))->dpi());
        $this->assertSame(203, (new LabelMedia(600, 400, -5))->dpi());
        $this->assertSame(300, (new LabelMedia(600, 400, 300))->dpi());
    }

    public function test_on_cups_it_prints_the_image_without_raw(): void
    {
        $command = PrinterPlatform::buildUnixGraphicPrintCommand('Munbyn_ITPP941P', '/tmp/label.png', new LabelMedia(609, 406, 203));

        $this->assertStringStartsWith('lp -d ', $command);
        $this->assertStringContainsString("'Munbyn_ITPP941P'", $command);
        $this->assertStringContainsString("'/tmp/label.png'", $command);
        // Il -o raw dello ZPL manderebbe alla stampante i byte del PNG.
        $this->assertStringNotContainsString('-o raw', $command);
        $this->assertStringContainsString('media=Custom.76x51mm', $command);
        $this->assertStringContainsString('fit-to-page', $command);
    }

    public function test_on_windows_it_calls_the_image_printing_script(): void
    {
        $commands = PrinterPlatform::buildWindowsGraphicPrintCommands(
            'Munbyn ITPP941P',
            'C:\\tmp\\label.png',
            new LabelMedia(609, 406, 203),
            'C:\\laragon\\tmp'
        );

        $this->assertCount(1, $commands);
        $this->assertStringContainsString('print-image.ps1', $commands[0]);
        $this->assertStringContainsString('-PrinterName', $commands[0]);
        $this->assertStringContainsString('Munbyn ITPP941P', $commands[0]);
        $this->assertStringContainsString('-WidthMm', $commands[0]);
        $this->assertStringContainsString('-HeightMm', $commands[0]);
    }

    public function test_the_windows_script_is_shipped_next_to_the_raw_one(): void
    {
        $this->assertFileExists(PrinterPlatform::windowsImagePrintScriptPath());
        $this->assertSame(
            dirname(PrinterPlatform::windowsPrintScriptPath()),
            dirname(PrinterPlatform::windowsImagePrintScriptPath())
        );
    }

    public function test_the_dispatcher_picks_the_branch_for_this_system(): void
    {
        $commands = PrinterPlatform::buildGraphicPrintCommands('P1', '/tmp/label.png', new LabelMedia(400, 300, 203));

        $this->assertNotSame([], $commands);
        $this->assertStringContainsString(
            PrinterPlatform::isWindows() ? 'print-image.ps1' : 'lp -d',
            $commands[0]
        );
    }
}
