<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\ZplBatch;
use PHPUnit\Framework\TestCase;

/**
 * Una serie di etichette deve uscire di fila, senza toccare la modalita'
 * della stampante.
 *
 * ^MM (strappo, tear on/rewind, ...) resta salvato nella stampante: se una
 * serie si interrompe prima dell'ultima etichetta la stampante resta nella
 * modalita' cambiata. Quindi mai ^MM: la modalita' e' quella impostata dal
 * pannello. Le copie della stessa etichetta diventano un formato solo con
 * ^PQ, che la stampante esegue di fila senza ritorno della carta.
 */
final class ZplBatchTest extends TestCase
{
    public function test_a_single_label_is_left_alone(): void
    {
        $this->assertSame('^XA^FD1^FS^XZ', ZplBatch::continuous('^XA^FD1^FS^XZ'));
    }

    public function test_a_series_never_changes_the_print_mode(): void
    {
        $this->assertSame(
            '^XA^FD1^FS^XZ^XA^FD2^FS^XZ^XA^FD3^FS^XZ',
            ZplBatch::continuous('^XA^FD1^FS^XZ^XA^FD2^FS^XZ^XA^FD3^FS^XZ')
        );
    }

    public function test_copies_of_one_label_are_one_format_with_a_print_quantity(): void
    {
        $this->assertSame('^XA^FD1^FS^PQ2^XZ', ZplBatch::repeat('^XA^FD1^FS^XZ', 2));
        $this->assertSame('^XA
^FD1^FS
^PQ3^XZ
', ZplBatch::repeat('^XA
^FD1^FS
^XZ
', 3));
        $this->assertSame('^XA^FD1^FS^XZ', ZplBatch::repeat('^XA^FD1^FS^XZ', 1));
        $this->assertSame('^XA^FD1^FS^XZ', ZplBatch::repeat('^XA^FD1^FS^XZ', 0));
    }

    /** In una serie (1, 1, 2, 2) ogni etichetta porta le sue copie con ^PQ. */
    public function test_copies_next_to_each_other_in_a_series_are_merged(): void
    {
        $this->assertSame(
            '^XA^FD1^FS^PQ2^XZ^XA^FD2^FS^PQ2^XZ^XA^FD1^FS^XZ',
            ZplBatch::continuous('^XA^FD1^FS^XZ^XA^FD1^FS^XZ^XA^FD2^FS^XZ^XA^FD2^FS^XZ^XA^FD1^FS^XZ')
        );
    }

    /** I comandi ZPL non distinguono maiuscole e minuscole. */
    public function test_lowercase_commands_count_too(): void
    {
        $this->assertSame('^xa^fd1^fs^PQ2^xz', ZplBatch::repeat('^xa^fd1^fs^xz', 2));
    }

    /**
     * Un formato che ha gia' la sua quantita' (^PQ) o numera da se' (^SN,
     * ^SF, che con ^PQ avanzerebbero a ogni copia) si ripete com'e'.
     */
    public function test_labels_that_count_on_their_own_are_repeated_as_they_are(): void
    {
        foreach (['^PQ2', '^SN001,1,Y', '^sf%%%,1'] as $command) {
            $label = '^XA^FD1^FS'.$command.'^XZ';

            $this->assertSame($label.$label, ZplBatch::repeat($label, 2), $command);
        }
    }

    /** Un blocco senza ^XZ non si sa dove chiuderlo: resta com'e'. */
    public function test_an_unterminated_label_is_repeated_as_it_is(): void
    {
        $this->assertSame('^XA^FD1^XA^FD1', ZplBatch::repeat('^XA^FD1', 2));
    }

    public function test_the_text_before_the_first_label_is_kept(): void
    {
        $this->assertSame('~JA^XA^FD1^FS^PQ2^XZ', ZplBatch::continuous('~JA^XA^FD1^FS^XZ^XA^FD1^FS^XZ'));
    }

    public function test_text_without_labels_is_left_alone(): void
    {
        $this->assertSame('', ZplBatch::continuous(''));
        $this->assertSame('~JA', ZplBatch::continuous('~JA'));
    }

    public function test_no_output_ever_changes_the_print_mode(): void
    {
        $zpl = ZplBatch::continuous(str_repeat('^XA^FD1^FS^XZ^XA^FD2^FS^XZ', 3));

        $this->assertStringNotContainsStringIgnoringCase('^MM', $zpl);
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
            ."^XA\n^FO20,15^XGR:{$first}.GRF,1,1^FS\n^FO20,17^XGR:{$second}.GRF,1,1^FS\n^FD1^FS\n^XZ"
            ."^XA\n^FO20,15^XGR:{$first}.GRF,1,1^FS\n^FO20,17^XGR:{$second}.GRF,1,1^FS\n^FD2^FS\n^XZ",
            $zpl
        );
    }

    /**
     * Le copie sono un formato solo: l'immagine si elabora una volta anche
     * restando ^GF, e non serve caricarla in memoria.
     */
    public function test_copies_of_a_label_with_an_image_keep_it_inline(): void
    {
        $this->assertSame('^XA^FO0,0^gfa,2,2,2,ffff^FS^PQ3^XZ', ZplBatch::repeat('^XA^FO0,0^gfa,2,2,2,ffff^FS^XZ', 3));
    }

    public function test_a_series_with_copies_loads_its_images_once(): void
    {
        $zpl = ZplBatch::continuous(str_repeat('^XA^FO0,0^GFA,2,2,2,FFFF^FD1^FS^XZ', 2).'^XA^FO0,0^GFA,2,2,2,FFFF^FD2^FS^XZ');

        $this->assertSame(1, substr_count($zpl, '~DGR:'));
        $this->assertSame(2, substr_count($zpl, '^XGR:'));
        $this->assertSame(1, substr_count($zpl, '^PQ2^XZ'));
        $this->assertStringNotContainsStringIgnoringCase('^GFA', $zpl);
    }

    /** Una etichetta sola resta come sempre: con ^GF la Citizen la stampa. */
    public function test_a_single_label_keeps_its_images_inline(): void
    {
        $this->assertSame('^XA^FO0,0^GFA,2,2,2,FFFF^FS^XZ', ZplBatch::continuous('^XA^FO0,0^GFA,2,2,2,FFFF^FS^XZ'));
    }
}
