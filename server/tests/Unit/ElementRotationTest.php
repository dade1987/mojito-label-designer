<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\ElementRotation;
use PHPUnit\Framework\TestCase;

/**
 * L'orientamento di un elemento sull'etichetta.
 *
 * Lo ZPL non ha una rotazione libera: ogni comando accetta una lettera fra
 * quattro orientamenti fissi. Chiedere 45 gradi non e' possibile, e far
 * scegliere un angolo qualsiasi all'utente prometterebbe qualcosa che la
 * stampante non sa fare.
 */
final class ElementRotationTest extends TestCase
{
    public function test_the_four_orientations_zpl_understands(): void
    {
        self::assertSame('N', ElementRotation::toZpl(0));
        self::assertSame('R', ElementRotation::toZpl(90));
        self::assertSame('I', ElementRotation::toZpl(180));
        self::assertSame('B', ElementRotation::toZpl(270));
    }

    public function test_an_angle_in_between_falls_back_to_upright(): void
    {
        // Meglio dritto che storto a caso: un 45 gradi non e' stampabile.
        self::assertSame('N', ElementRotation::toZpl(45));
        self::assertSame('N', ElementRotation::toZpl(-90));
        self::assertSame('N', ElementRotation::toZpl(null));
    }

    public function test_a_full_turn_is_upright(): void
    {
        self::assertSame('N', ElementRotation::toZpl(360));
    }

    public function test_it_reads_the_angle_off_an_element(): void
    {
        self::assertSame('R', ElementRotation::forElement(['rotation' => 90]));
        self::assertSame('N', ElementRotation::forElement([]));
    }
}
