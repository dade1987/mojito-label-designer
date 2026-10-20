<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\SerialSeriesSettings;
use PHPUnit\Framework\TestCase;

/**
 * Le impostazioni della stampa a serie salvate nel layout: stesse regole del
 * designer (src/utils/serialRange.js, normalizeSeriesSettings).
 */
final class SerialSeriesSettingsTest extends TestCase
{
    public function test_valori_buoni_restano_quelli(): void
    {
        $this->assertSame(
            ['codeField' => 'qr_1', 'numberField' => 'text_1', 'prefix' => 'CTW10140T20S0926', 'suffix' => 'B', 'pad' => 3, 'step' => 2],
            SerialSeriesSettings::sanitize(['codeField' => 'qr_1', 'numberField' => 'text_1', 'prefix' => 'CTW10140T20S0926', 'suffix' => 'B', 'pad' => '3', 'step' => 2, 'extra' => 'via']),
        );
    }

    public function test_valori_assurdi_tornano_ammessi(): void
    {
        $empty = ['codeField' => '', 'numberField' => '', 'prefix' => '', 'suffix' => '', 'pad' => 0, 'step' => 1];

        $this->assertSame($empty, SerialSeriesSettings::sanitize('rotto'));
        $this->assertSame($empty, SerialSeriesSettings::sanitize(['pad' => -4, 'step' => 0, 'codeField' => 7, 'prefix' => null]));
        $this->assertSame($empty, SerialSeriesSettings::sanitize(['pad' => 'x', 'step' => 2.5]));
        $this->assertSame(12, SerialSeriesSettings::sanitize(['pad' => 40])['pad']);
        $this->assertSame(1, SerialSeriesSettings::sanitize(['step' => '1.5'])['step']);
    }
}
