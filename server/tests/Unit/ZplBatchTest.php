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

    /**
     * La Citizen non supporta ^GF (lo emula, lentamente): in una serie ogni
     * etichetta la costringe a rielaborare tutte le immagini e la stampante
     * si ferma ad aspettare. Le immagini si caricano una volta sola in
     * memoria (~DG) prima della serie e ogni etichetta le richiama (^XG).
     */
    public function test_images_of_a_series_are_loaded_once_and_recalled(): void
    {
        $label = static fn (string $serial): string => "^XA\n^FO20,15^GFA,4,4,2,F00F0FF0^FS\n^FO20,17^GFA,2,2,2,FFFF^FS\n^FD{$serial}^FS\n^XZ";

        $zpl = ZplBatch::continuous($label('1').$label('2'));

        $first = sprintf('M%07X', crc32('2,F00F0FF0') & 0xFFFFFFF);
        $second = sprintf('M%07X', crc32('2,FFFF') & 0xFFFFFFF);

        $this->assertSame(
            "~DGR:{$first}.GRF,4,2,F00F0FF0\n~DGR:{$second}.GRF,2,2,FFFF\n"
            ."^XA^MMR\n^FO20,15^XGR:{$first}.GRF,1,1^FS\n^FO20,17^XGR:{$second}.GRF,1,1^FS\n^FD1^FS\n^XZ"
            ."^XA^MMT\n^FO20,15^XGR:{$first}.GRF,1,1^FS\n^FO20,17^XGR:{$second}.GRF,1,1^FS\n^FD2^FS\n^XZ",
            $zpl
        );
    }

    public function test_copies_of_a_label_with_an_image_load_it_once(): void
    {
        $zpl = ZplBatch::repeat('^XA^FO0,0^gfa,2,2,2,ffff^FS^XZ', 3);

        $this->assertSame(1, substr_count($zpl, '~DGR:'));
        $this->assertSame(3, substr_count($zpl, '^XGR:'));
        $this->assertStringNotContainsStringIgnoringCase('^GFA', $zpl);
    }

    /** Una etichetta sola resta come sempre: con ^GF la Citizen la stampa. */
    public function test_a_single_label_keeps_its_images_inline(): void
    {
        $this->assertSame('^XA^FO0,0^GFA,2,2,2,FFFF^FS^XZ', ZplBatch::continuous('^XA^FO0,0^GFA,2,2,2,FFFF^FS^XZ'));
    }
}
