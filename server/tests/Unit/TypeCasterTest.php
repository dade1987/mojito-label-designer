<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use InvalidArgumentException;
use Mojito\Label\TypeCaster;
use PHPUnit\Framework\TestCase;

final class TypeCasterTest extends TestCase
{
    public function test_string_keyed_array(): void
    {
        $input = ['a' => 1, 'b' => 'x'];
        $this->assertSame($input, TypeCaster::stringKeyedArray($input));
    }

    public function test_assert_string_keyed_array_rejects_non_array(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TypeCaster::assertStringKeyedArray('not-array');
    }

    public function test_string_coercion(): void
    {
        $this->assertSame('hello', TypeCaster::string('hello'));
        $this->assertSame('42', TypeCaster::string(42));
        $this->assertSame('1', TypeCaster::string(true));
        $this->assertSame('', TypeCaster::string(false));
        $this->assertSame('3.5', TypeCaster::string(3.5));
        $this->assertSame('fallback', TypeCaster::string(null, 'fallback'));
        $this->assertSame('fallback', TypeCaster::string([], 'fallback'));
    }

    public function test_int_coercion(): void
    {
        $this->assertSame(7, TypeCaster::int(7));
        $this->assertSame(12, TypeCaster::int('12'));
        $this->assertSame(0, TypeCaster::int('abc', 0));
        $this->assertSame(99, TypeCaster::int(null, 99));
    }

    public function test_bool_coercion(): void
    {
        $this->assertTrue(TypeCaster::bool(true));
        $this->assertFalse(TypeCaster::bool(false));
        $this->assertTrue(TypeCaster::bool('yes', true));
        $this->assertFalse(TypeCaster::bool('yes', false));
    }

    public function test_a_decimal_is_truncated_instead_of_being_thrown_away(): void
    {
        // Un decimale arrivato dal JSON e' un numero, non un valore illeggibile:
        // scartarlo e sostituirlo col default faceva stampare un codice a barre
        // di dimensione 2 a chi ne aveva scritto 2,5, senza dire niente.
        self::assertSame(2, TypeCaster::int(2.5, 9));
        self::assertSame(3, TypeCaster::int(3.9, 9));
        self::assertSame(-2, TypeCaster::int(-2.5, 9));
    }

    public function test_a_decimal_string_is_truncated_too(): void
    {
        self::assertSame(2, TypeCaster::int('2.5', 9));
    }

    public function test_what_is_not_a_number_still_falls_back(): void
    {
        self::assertSame(9, TypeCaster::int('due e mezzo', 9));
        self::assertSame(9, TypeCaster::int(null, 9));
        self::assertSame(9, TypeCaster::int(NAN, 9));
    }
}
