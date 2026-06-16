<?php

declare(strict_types=1);

namespace Mojito\Label;

use RuntimeException;

/**
 * Servizio di stampa etichette ZPL (CUPS su Linux, Winspool RAW su Windows).
 * Compatibile con Citizen CL-S700 in emulazione ZPL2.
 */
final class LabelPrinterService
{
    /** @deprecated Usare PrinterPlatform::DEFAULT_PRINTER */
    public const DEFAULT_PRINTER = PrinterPlatform::DEFAULT_PRINTER;

    public function __construct(
        private readonly ZplBuilder $zplBuilder = new ZplBuilder,
        private readonly ShellCommandRunner $commandRunner = new ShellCommandRunner,
        private string $printerName = '',
        private readonly ?\Closure $tempFileFactory = null,
    ) {
        if ($this->printerName === '') {
            $this->printerName = PrinterPlatform::defaultPrinterName();
        }
    }

    public function setPrinterName(string $printerName): self
    {
        $this->printerName = $printerName;

        return $this;
    }

    public function getPrinterName(): string
    {
        return $this->printerName;
    }

    /**
     * @return array{printers: list<string>, platform: string}
     */
    public function listPrintersInfo(): array
    {
        return [
            'printers' => $this->listPrinters(),
            'platform' => PrinterPlatform::osFamily(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function buildZpl(array $data): string
    {
        if (isset($data['template']) && is_array($data['template'])) {
            $template = TypeCaster::stringKeyedArray($data['template']);
            $values = isset($data['values']) && is_array($data['values'])
                ? TypeCaster::stringKeyedArray($data['values'])
                : [];

            return $this->zplBuilder->renderTemplate($template, $values);
        }

        return $this->zplBuilder->renderDefaultLabel($data);
    }

    public function printZpl(string $zpl): void
    {
        if (trim($this->printerName) === '') {
            throw new RuntimeException('Nessuna stampante selezionata.');
        }

        $file = $this->createTempFile();

        if ($file === false) {
            throw new RuntimeException('Impossibile creare file temporaneo per la stampa.');
        }

        try {
            if (@file_put_contents($file, $zpl) === false) {
                throw new RuntimeException('Impossibile scrivere ZPL nel file temporaneo.');
            }

            $command = PrinterPlatform::buildPrintCommand($this->printerName, $file);
            $result = $this->commandRunner->run($command);

            if ($result['code'] !== 0) {
                throw new RuntimeException('Errore stampa etichetta: '.implode("\n", $result['output']));
            }
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function createTempFile(): string|false
    {
        if ($this->tempFileFactory instanceof \Closure) {
            return ($this->tempFileFactory)();
        }

        return tempnam(sys_get_temp_dir(), 'label_');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function printLabel(array $data): void
    {
        $this->printZpl($this->buildZpl($data));
    }

    /**
     * @return list<string>
     */
    public function listPrinters(): array
    {
        return PrinterPlatform::listPrinters($this->commandRunner);
    }
}
