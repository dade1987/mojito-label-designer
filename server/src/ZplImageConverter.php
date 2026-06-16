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
    public function fromBinary(string $binary, int $targetWidth = 0, int $targetHeight = 0): ?array
    {
        if ($binary === '') {
            return null;
        }

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            return null;
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
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
            $width = $targetWidth;
            $height = $targetHeight;
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
                $isBlack = $alpha < 127 && $luminance < 128;

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

            while (count($hexLines) % $bytesPerRow !== 0 && $bit === 7) {
                // padding row già completa
                break;
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
