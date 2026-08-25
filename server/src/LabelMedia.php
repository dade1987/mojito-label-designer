<?php

declare(strict_types=1);

namespace Mojito\Label;

/**
 * La misura fisica di un'etichetta disegnata in dot.
 *
 * Un layout Mojito ragiona in dot di stampa; una coda di stampa di sistema
 * ragiona in millimetri di carta. Senza dichiarare la misura, CUPS e lo
 * spooler di Windows assumono A4 e sputano l'etichetta in un angolo di un
 * foglio: la conversione va fatta una volta sola e sempre allo stesso modo.
 */
final class LabelMedia
{
    /** La risoluzione delle termiche da etichette più diffuse. */
    public const DEFAULT_DPI = 203;

    private readonly int $dpi;

    public function __construct(
        private readonly int $widthDots,
        private readonly int $heightDots,
        int $dpi = self::DEFAULT_DPI,
    ) {
        $this->dpi = $dpi > 0 ? $dpi : self::DEFAULT_DPI;
    }

    /**
     * @param  array<string, mixed>  $template
     */
    public static function fromTemplate(array $template): self
    {
        return new self(
            TypeCaster::int($template['labelWidth'] ?? 0),
            TypeCaster::int($template['labelHeight'] ?? 0),
            TypeCaster::int($template['dpi'] ?? self::DEFAULT_DPI, self::DEFAULT_DPI),
        );
    }

    public function dpi(): int
    {
        return $this->dpi;
    }

    public function widthDots(): int
    {
        return $this->widthDots;
    }

    public function heightDots(): int
    {
        return $this->heightDots;
    }

    public function widthMillimetres(): float
    {
        return $this->widthDots / $this->dpi * 25.4;
    }

    public function heightMillimetres(): float
    {
        return $this->heightDots / $this->dpi * 25.4;
    }

    /**
     * Il nome del formato carta come lo vuole CUPS (`media=Custom.76x51mm`).
     */
    public function cupsMediaName(): string
    {
        return sprintf(
            'Custom.%dx%dmm',
            max(1, (int) round($this->widthMillimetres())),
            max(1, (int) round($this->heightMillimetres()))
        );
    }
}
