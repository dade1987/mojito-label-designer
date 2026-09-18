<?php

declare(strict_types=1);

namespace Mojito\Label;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use GdImage;
use InvalidArgumentException;
use Picqer\Barcode\Types\TypeCode128;
use Picqer\Barcode\Types\TypeCode39;
use RuntimeException;

/**
 * Disegna un layout etichetta in un PNG, un pixel per dot di stampa.
 *
 * Serve alle stampanti che non parlano ZPL (per esempio una Munbyn ITPP941P):
 * lì l'etichetta non si descrive con dei comandi, si manda già disegnata e ci
 * pensa il driver di sistema. Le regole di posizione, dimensione e valori sono
 * le stesse di ZplBuilder, così lo stesso layout esce uguale sulle due strade.
 */
final class LabelRasterRenderer
{
    /** Oltre questa misura non è più un'etichetta: è un errore di unità di misura. */
    private const MAX_SIDE_DOTS = 20000;

    private const MAX_TOTAL_DOTS = 50000000;

    /** Spazio fra le barre e la riga leggibile sotto, in dot. */
    private const BARCODE_TEXT_GAP = 2;

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $values
     */
    public function renderPng(array $template, array $values = []): string
    {
        $canvas = $this->renderImage($template, $values);

        ob_start();
        imagepng($canvas, null, 9);
        $png = (string) ob_get_clean();

        return $png;
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $values
     */
    public function renderImage(array $template, array $values = []): GdImage
    {
        // Come per lo ZPL: i campi non passati li riempie il layout con i
        // valori dichiarati sulle sue sorgenti dati.
        $values = array_merge(TemplateDefaults::forTemplate($template), $values);

        [$width, $height] = $this->sensibleSize(
            TypeCaster::int($template['labelWidth'] ?? 0),
            TypeCaster::int($template['labelHeight'] ?? 0),
        );

        $canvas = imagecreatetruecolor($width, $height);

        if ($canvas === false) {
            throw new RuntimeException('Impossibile creare l\'immagine dell\'etichetta.');
        }

        imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, $this->white($canvas));

        $originX = max(0, TypeCaster::int($template['originX'] ?? 0));
        $originY = max(0, TypeCaster::int($template['originY'] ?? 0));

        $elements = $template['elements'] ?? [];

        if (! is_array($elements)) {
            throw new InvalidArgumentException('Il campo template.elements deve essere un array.');
        }

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            $this->drawElement($canvas, TypeCaster::stringKeyedArray($element), $values, $originX, $originY);
        }

        return $canvas;
    }

    /**
     * @return array{0: int<1, max>, 1: int<1, max>}
     */
    private function sensibleSize(int $width, int $height): array
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('Misura etichetta non valida: larghezza e altezza devono essere maggiori di zero.');
        }

        if ($width > self::MAX_SIDE_DOTS || $height > self::MAX_SIDE_DOTS || ($width * $height) > self::MAX_TOTAL_DOTS) {
            throw new InvalidArgumentException('Misura etichetta fuori scala: controlla di aver espresso larghezza e altezza in dot.');
        }

        return [$width, $height];
    }

    private function white(GdImage $image): int
    {
        return (int) imagecolorallocate($image, 255, 255, 255);
    }

    private function black(GdImage $image): int
    {
        return (int) imagecolorallocate($image, 0, 0, 0);
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  array<string, mixed>  $values
     */
    private function drawElement(GdImage $canvas, array $element, array $values, int $originX, int $originY): void
    {
        $x = $originX + TypeCaster::int($element['x'] ?? 0);
        $y = $originY + TypeCaster::int($element['y'] ?? 0);

        match (TypeCaster::string($element['type'] ?? 'text', 'text')) {
            'text' => $this->drawText($canvas, $element, $values, $x, $y),
            'barcode' => $this->drawBarcode($canvas, $element, $values, $x, $y),
            'qr' => $this->drawQr($canvas, $element, $values, $x, $y),
            'image' => $this->drawImage($canvas, $element, $x, $y),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  array<string, mixed>  $values
     */
    private function drawText(GdImage $canvas, array $element, array $values, int $x, int $y): void
    {
        $text = TypeCaster::string($element['prefix'] ?? '')
            .$this->resolveValue($element, $values)
            .TypeCaster::string($element['suffix'] ?? '');

        if ($text === '') {
            return;
        }

        $this->paintText(
            $canvas,
            $text,
            $x,
            $y,
            max(1, TypeCaster::int($element['fontHeight'] ?? 30, 30)),
            max(1, TypeCaster::int($element['fontWidth'] ?? 30, 30)),
            TypeCaster::bool($element['bold'] ?? false, false),
            TypeCaster::bool($element['underline'] ?? false, false),
            ElementRotation::degreesForElement($element),
        );
    }

    /**
     * Disegna il testo con l'angolo in alto a sinistra della cella in (x, y),
     * come fa ^FO in ZPL.
     */
    private function paintText(
        GdImage $canvas,
        string $text,
        int $x,
        int $y,
        int $fontHeight,
        int $fontWidth,
        bool $bold,
        bool $underline,
        int $rotation,
    ): void {
        $font = $bold ? LabelFont::bold() : LabelFont::regular();

        if ($font === null) {
            $this->paintBuiltInText($canvas, $text, $x, $y, $fontHeight, $rotation);

            return;
        }

        $size = $fontHeight * LabelFont::POINT_RATIO;
        $box = imagettfbbox($size, 0, $font, $text);

        if ($box === false) {
            $this->paintBuiltInText($canvas, $text, $x, $y, $fontHeight, $rotation);

            return;
        }

        $textWidth = max(1, (int) ceil(abs($box[2] - $box[0])) + 2);
        $layerHeight = max(1, $fontHeight + max(2, (int) round($fontHeight / 4)));
        $layer = $this->transparentLayer($textWidth + 2, $layerHeight);
        // La linea di base a 0.8 dell'altezza cella tiene le maiuscole dentro
        // la cella dichiarata e non si sposta cambiando testo, come farebbe
        // invece appoggiarsi al riquadro reale dei glifi.
        $baseline = (int) round($fontHeight * 0.8);

        imagettftext($layer, $size, 0, 1, $baseline, $this->black($layer), $font, $text);

        if ($bold) {
            // Doppia passata di un dot, la stessa finta del grassetto in ZPL.
            imagettftext($layer, $size, 0, 2, $baseline, $this->black($layer), $font, $text);
        }

        if ($underline) {
            $thickness = max(1, (int) round($fontHeight / 10));
            $underlineY = min($layerHeight - 1, $baseline + max(1, (int) round($fontHeight / 8)));
            imagefilledrectangle($layer, 1, $underlineY, $textWidth, min($layerHeight - 1, $underlineY + $thickness - 1), $this->black($layer));
        }

        $layer = $this->scaleHorizontally($layer, $fontWidth, $fontHeight);
        $this->stamp($canvas, $layer, $x, $y, $rotation);
    }

    /**
     * Ripiego quando non c'è nessun TrueType: il font interno di GD, scalato.
     */
    private function paintBuiltInText(GdImage $canvas, string $text, int $x, int $y, int $fontHeight, int $rotation): void
    {
        $layer = $this->transparentLayer(max(1, imagefontwidth(5) * strlen($text)), max(1, imagefontheight(5)));
        imagestring($layer, 5, 0, 0, $text, $this->black($layer));

        $scale = max(1, (int) round($fontHeight / imagefontheight(5)));
        $scaled = $this->transparentLayer(imagesx($layer) * $scale, imagesy($layer) * $scale);
        imagecopyresampled($scaled, $layer, 0, 0, 0, 0, imagesx($scaled), imagesy($scaled), imagesx($layer), imagesy($layer));

        $this->stamp($canvas, $scaled, $x, $y, $rotation);
    }

    private function scaleHorizontally(GdImage $layer, int $fontWidth, int $fontHeight): GdImage
    {
        if ($fontWidth === $fontHeight) {
            return $layer;
        }

        $width = max(1, (int) round(imagesx($layer) * ($fontWidth / $fontHeight)));
        $scaled = $this->transparentLayer($width, imagesy($layer));
        imagecopyresampled($scaled, $layer, 0, 0, 0, 0, $width, imagesy($layer), imagesx($layer), imagesy($layer));

        return $scaled;
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  array<string, mixed>  $values
     */
    private function drawBarcode(GdImage $canvas, array $element, array $values, int $x, int $y): void
    {
        $value = $this->resolveValue($element, $values);

        if ($value === '') {
            return;
        }

        $moduleWidth = max(1, TypeCaster::int($element['moduleWidth'] ?? 2, 2));
        $height = max(1, TypeCaster::int($element['height'] ?? 100, 100));
        $type = TypeCaster::string($element['barcodeType'] ?? 'code128', 'code128');

        $barcode = $type === 'code39'
            ? (new TypeCode39)->getBarcode(strtoupper($value))
            : (new TypeCode128)->getBarcode($value);

        $layer = $this->transparentLayer(max(1, $barcode->getWidth() * $moduleWidth), $height);
        $cursor = 0;

        foreach ($barcode->getBars() as $bar) {
            $barWidth = $bar->getWidth() * $moduleWidth;

            if ($bar->isBar() && $barWidth > 0) {
                imagefilledrectangle($layer, $cursor, 0, $cursor + $barWidth - 1, $height - 1, $this->black($layer));
            }

            $cursor += $barWidth;
        }

        if (TypeCaster::bool($element['showText'] ?? true, true)) {
            $layer = $this->appendHumanReadable($layer, $value, TypeCaster::int($element['textHeight'] ?? 0, 0));
        }

        $this->stamp($canvas, $layer, $x, $y, ElementRotation::degreesForElement($element));
    }

    /**
     * La riga leggibile sotto le barre, centrata come la stampa una Zebra.
     */
    private function appendHumanReadable(GdImage $bars, string $value, int $textHeight): GdImage
    {
        $textHeight = $textHeight > 0 ? $textHeight : 20;
        $width = imagesx($bars);
        $composed = $this->transparentLayer($width, imagesy($bars) + self::BARCODE_TEXT_GAP + $textHeight);

        imagecopy($composed, $bars, 0, 0, 0, 0, $width, imagesy($bars));

        $font = LabelFont::regular();

        if ($font === null) {
            return $composed;
        }

        $size = $textHeight * LabelFont::POINT_RATIO;
        $box = imagettfbbox($size, 0, $font, $value);
        $textWidth = $box === false ? 0 : (int) ceil(abs($box[2] - $box[0]));
        $left = max(0, (int) round(($width - $textWidth) / 2));

        imagettftext(
            $composed,
            $size,
            0,
            $left,
            imagesy($bars) + self::BARCODE_TEXT_GAP + (int) round($textHeight * 0.8),
            $this->black($composed),
            $font,
            $value
        );

        return $composed;
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  array<string, mixed>  $values
     */
    private function drawQr(GdImage $canvas, array $element, array $values, int $x, int $y): void
    {
        $value = $this->resolveValue($element, $values);

        if ($value === '') {
            return;
        }

        $magnification = max(1, min(10, TypeCaster::int($element['magnification'] ?? 4, 4)));
        $errorCorrection = TypeCaster::string($element['errorCorrection'] ?? 'M', 'M');

        if (! in_array($errorCorrection, ['H', 'Q', 'M', 'L'], true)) {
            $errorCorrection = 'M';
        }

        $matrix = (new QRCode(new QROptions([
            'eccLevel' => match ($errorCorrection) {
                'L' => EccLevel::L,
                'Q' => EccLevel::Q,
                'H' => EccLevel::H,
                default => EccLevel::M,
            },
            // Il quiet zone lo decide il layout, non la libreria: in ZPL ^BQ
            // il codice parte esattamente dall'origine del campo.
            'addQuietzone' => false,
        ])))->addByteSegment($value)->getQRMatrix();

        $size = $matrix->getSize();
        $layer = $this->transparentLayer($size * $magnification, $size * $magnification);
        $black = $this->black($layer);

        for ($row = 0; $row < $size; $row++) {
            for ($column = 0; $column < $size; $column++) {
                if ($matrix->check($column, $row)) {
                    imagefilledrectangle(
                        $layer,
                        $column * $magnification,
                        $row * $magnification,
                        ($column + 1) * $magnification - 1,
                        ($row + 1) * $magnification - 1,
                        $black
                    );
                }
            }
        }

        $this->stamp($canvas, $layer, $x, $y, ElementRotation::degreesForElement($element));
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function drawImage(GdImage $canvas, array $element, int $x, int $y): void
    {
        $binary = $this->decodeImageData(TypeCaster::string($element['imageData'] ?? ''));

        if ($binary === null) {
            return;
        }

        $source = @imagecreatefromstring($binary);

        if ($source === false) {
            return;
        }

        $targetWidth = TypeCaster::int($element['width'] ?? 0);
        $targetHeight = TypeCaster::int($element['height'] ?? 0);
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $width = $targetWidth > 0 ? $targetWidth : $sourceWidth;
        $height = $targetHeight > 0 ? $targetHeight : $sourceHeight;

        // L'anteprima e lo ZPL mostrano la foto con le proporzioni originali,
        // centrata nel riquadro (object-fit: contain): qui si fa lo stesso,
        // altrimenti la stampa grafica esce stirata mentre lo schermo la
        // mostrava giusta. Il margine resta trasparente, cioe' bianco.
        $scale = min($width / $sourceWidth, $height / $sourceHeight);
        $drawWidth = max(1, (int) round($sourceWidth * $scale));
        $drawHeight = max(1, (int) round($sourceHeight * $scale));
        $offsetX = intdiv($width - $drawWidth, 2);
        $offsetY = intdiv($height - $drawHeight, 2);

        $resized = $this->transparentLayer($width, $height);
        imagecopyresampled($resized, $source, $offsetX, $offsetY, 0, 0, $drawWidth, $drawHeight, $sourceWidth, $sourceHeight);

        // Le termiche stampano solo nero o niente: la soglia è quella scelta
        // nel designer, così l'anteprima e la stampa dicono la stessa cosa.
        $threshold = min(255, max(1, TypeCaster::int($element['threshold'] ?? 128, 128)));
        $this->stamp($canvas, $this->toMonochrome($resized, $threshold), $x, $y, ElementRotation::degreesForElement($element));
    }

    private function decodeImageData(string $imageData): ?string
    {
        if ($imageData === '') {
            return null;
        }

        if (str_starts_with($imageData, 'data:')) {
            $commaPosition = strpos($imageData, ',');

            if ($commaPosition === false) {
                return null;
            }

            $imageData = substr($imageData, $commaPosition + 1);
        }

        $binary = base64_decode($imageData, true);

        return $binary === false || $binary === '' ? null : $binary;
    }

    private function toMonochrome(GdImage $image, int $threshold): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $result = $this->transparentLayer($width, $height);
        $black = $this->black($result);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $index = imagecolorat($image, $x, $y);

                if ($index === false) {
                    continue;
                }

                $colors = imagecolorsforindex($image, $index);

                if ($colors['alpha'] > 96) {
                    continue;
                }

                $luminance = (int) round(0.299 * $colors['red'] + 0.587 * $colors['green'] + 0.114 * $colors['blue']);

                if ($luminance < $threshold) {
                    imagesetpixel($result, $x, $y, $black);
                }
            }
        }

        return $result;
    }

    private function transparentLayer(int $width, int $height): GdImage
    {
        $layer = imagecreatetruecolor(max(1, $width), max(1, $height));

        if ($layer === false) {
            throw new RuntimeException('Impossibile creare il livello di disegno.');
        }

        imagealphablending($layer, false);
        imagesavealpha($layer, true);
        imagefilledrectangle($layer, 0, 0, imagesx($layer) - 1, imagesy($layer) - 1, (int) imagecolorallocatealpha($layer, 255, 255, 255, 127));
        imagealphablending($layer, true);

        return $layer;
    }

    /**
     * Incolla un livello sull'etichetta con l'angolo in alto a sinistra in
     * (x, y), ruotandolo se l'elemento lo chiede.
     */
    private function stamp(GdImage $canvas, GdImage $layer, int $x, int $y, int $rotation): void
    {
        if ($rotation !== 0) {
            $transparent = (int) imagecolorallocatealpha($layer, 255, 255, 255, 127);
            // imagerotate gira in senso antiorario, ZPL in senso orario.
            $rotated = imagerotate($layer, 360 - ($rotation % 360), $transparent);

            if ($rotated !== false) {
                $layer = $rotated;
                imagealphablending($layer, true);
                imagesavealpha($layer, true);
            }
        }

        imagecopy($canvas, $layer, $x, $y, 0, 0, imagesx($layer), imagesy($layer));
    }

    /**
     * Le stesse regole di ZplBuilder: valore passato, poi statico, poi default.
     *
     * @param  array<string, mixed>  $element
     * @param  array<string, mixed>  $values
     */
    private function resolveValue(array $element, array $values): string
    {
        $dataSource = TypeCaster::string($element['dataSource'] ?? '');

        if ($dataSource !== '' && array_key_exists($dataSource, $values)) {
            return TypeCaster::string($values[$dataSource]);
        }

        if (isset($element['staticValue'])) {
            return TypeCaster::string($element['staticValue']);
        }

        if ($dataSource !== '' && isset($element['defaultValue'])) {
            return TypeCaster::string($element['defaultValue']);
        }

        return '';
    }
}
