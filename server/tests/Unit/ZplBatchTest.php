<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\ZplBatch;
use PHPUnit\Framework\TestCase;

/**
 * Una serie di etichette deve uscire di fila.
 *
 * In ZPL ogni etichetta e' un blocco ^XA...^XZ, e dopo ogni blocco la
 * stampante porta l'etichetta alla barra di strappo e poi, prima della
 * successiva, riavvolge la carta: la serie esce "una, indietro, un'altra".
 * La Citizen in emulazione ZPL non conosce ^XB, ma accetta ^MMR ("tear on":
 * l'etichetta dopo resta sotto la testina, niente ritorno). Quindi: ^MMR in
 * tutte tranne l'ultima, ^MMT (tear off, il valore di serie) nell'ultima,
 * che si ferma alla barra di strappo come sempre.
 */
final class ZplBatchTest extends TestCase
{
    public function test_a_single_label_is_left_alone(): void
    {
        $this->assertSame('^XA^FD1^FS^XZ', ZplBatch::continuous('^XA^FD1^FS^XZ'));
    }

    public function test_every_label_but_the_last_goes_on_without_backfeed(): void
    {
        $this->assertSame(
            '^XA^MMR^FD1^FS^XZ^XA^MMR^FD2^FS^XZ^XA^MMT^FD3^FS^XZ',
            ZplBatch::continuous('^XA^FD1^FS^XZ^XA^FD2^FS^XZ^XA^FD3^FS^XZ')
        );
    }

    public function test_newlines_between_labels_are_kept(): void
    {
        $this->assertSame(
            "^XA^MMR\n^FD1^FS\n^XZ\n^XA^MMT\n^FD2^FS\n^XZ\n",
            ZplBatch::continuous("^XA\n^FD1^FS\n^XZ\n^XA\n^FD2^FS\n^XZ\n")
        );
    }

    /** I comandi ZPL non distinguono maiuscole e minuscole. */
    public function test_lowercase_commands_count_too(): void
    {
        $this->assertSame('^xa^MMR^fd1^fs^xz^XA^MMT^FD2^FS^XZ', ZplBatch::continuous('^xa^fd1^fs^xz^XA^FD2^FS^XZ'));
    }

    public function test_copies_of_one_label_become_a_continuous_series(): void
    {
        $this->assertSame('^XA^MMR^FD1^FS^XZ^XA^MMT^FD1^FS^XZ', ZplBatch::repeat('^XA^FD1^FS^XZ', 2));
        $this->assertSame('^XA^FD1^FS^XZ', ZplBatch::repeat('^XA^FD1^FS^XZ', 1));
        $this->assertSame('^XA^FD1^FS^XZ', ZplBatch::repeat('^XA^FD1^FS^XZ', 0));
    }

    public function test_text_without_labels_is_left_alone(): void
    {
        $this->assertSame('', ZplBatch::continuous(''));
        $this->assertSame('~JA', ZplBatch::continuous('~JA'));
    }
}
