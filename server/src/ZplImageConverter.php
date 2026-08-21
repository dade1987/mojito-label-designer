<?php

declare(strict_types=1);

namespace Mojito\Label;

/**
 * Converte immagini PNG/JPEG/GIF in formato ^GFA (ASCII hex) per ZPL.
 */
class ZplImageConverter
{
    /**
     * @return array{totalBytes: int, bytesPerRow: int, hexData: string}|null
     */
    public function fromBinary(string $binary, int $targetWidth = 0, int $targetHeight = 0, int $threshold = 128, int $rotation = 0): ?array
    {
        if ($binary === '') {
            return null;
        }

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            return null;
        }

        // Sui PNG/GIF a palette imagecolorat() restituisce l'indice di
        // tavolozza, non il colore: la soglia letta su quegli indici produce
        // un ammasso di punti senza senso al posto del logo. Convertire a
        // truecolor fa leggere colori veri qualunque sia il formato sorgente.
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($targetWidth > 0 && $targetHeight > 0 && ($targetWidth !== $width || $targetHeight !== $height)) {
            $resized = $this->createResizeCanvas($targetWidth, $targetHeight);

            if ($resized === false) {
                imagedestroy($image);

                return null;
            }

            imagealphablending($resized, false);
            imagesavealpha($resized, true);

            // L'anteprima mostra l'immagine con le proporzioni originali,
            // centrata nel riquadro (object-fit: contain): qui si fa lo
            // stesso, altrimenti la stampa esce stirata mentre lo schermo
            // la mostrava giusta. Il margine resta trasparente, cioe' bianco
            // sulla carta — il canvas truecolor nasce nero opaco.
            $blank = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefilledrectangle($resized, 0, 0, $targetWidth - 1, $targetHeight - 1, $blank === false ? 0 : $blank);

            $scale = min($targetWidth / $width, $targetHeight / $height);
            $drawWidth = max(1, (int) round($width * $scale));
            $drawHeight = max(1, (int) round($height * $scale));
            $offsetX = intdiv($targetWidth - $drawWidth, 2);
            $offsetY = intdiv($targetHeight - $drawHeight, 2);

            imagecopyresampled($resized, $image, $offsetX, $offsetY, 0, 0, $drawWidth, $drawHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
            $width = $targetWidth;
            $height = $targetHeight;
        }

        // ^GF non conosce l'orientamento: la rotazione va fatta sui pixel
        // prima di convertirli. Come per i testi ZPL, il riquadro ruotato
        // tiene fermo l'angolo in alto a sinistra su ^FO (per 90/270 le
        // dimensioni si scambiano), che e' cio' che l'anteprima disegna.
        if ($rotation === 90 || $rotation === 180 || $rotation === 270) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $blank = imagecolorallocatealpha($image, 0, 0, 0, 127);
            // GD ruota in senso antiorario, la rotazione scelta e' oraria.
            $rotated = imagerotate($image, 360 - $rotation, $blank === false ? 0 : $blank);

            if ($rotated !== false) {
                imagedestroy($image);
                $image = $rotated;
                imagesavealpha($image, true);
                $width = imagesx($image);
                $height = imagesy($image);
            }
        }

        $bytesPerRow = (int) ceil($width / 8);
        $hexLines = [];

        for ($y = 0; $y < $height; $y++) {
            $byte = 0;
            $bit = 7;

            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $red = ($rgb >> 16) & 0xFF;
                $green = ($rgb >> 8) & 0xFF;
                $blue = $rgb & 0xFF;
                $alpha = ($rgb & 0x7F000000) >> 24;
                $luminance = (int) round(0.299 * $red + 0.587 * $green + 0.114 * $blue);
                $isBlack = $alpha < 127 && $luminance < $threshold;

                if ($isBlack) {
                    $byte |= 1 << $bit;
                }

                $bit--;

                if ($bit < 0) {
                    $hexLines[] = sprintf('%02X', $byte);
                    $byte = 0;
                    $bit = 7;
                }
            }

            if ($bit !== 7) {
                $hexLines[] = sprintf('%02X', $byte);
            }
        }

        imagedestroy($image);

        $totalBytes = $bytesPerRow * $height;
        $hexData = implode('', $hexLines);

        // Normalizza lunghezza hex
        $expectedHexLen = $totalBytes * 2;

        if (strlen($hexData) < $expectedHexLen) {
            $hexData = str_pad($hexData, $expectedHexLen, '0');
        } elseif (strlen($hexData) > $expectedHexLen) {
            $hexData = substr($hexData, 0, $expectedHexLen);
        }

        return [
            'totalBytes' => $totalBytes,
            'bytesPerRow' => $bytesPerRow,
            'hexData' => $hexData,
        ];
    }

    protected function createResizeCanvas(int $width, int $height): \GdImage|false
    {
        return imagecreatetruecolor(max(1, $width), max(1, $height));
    }
}
