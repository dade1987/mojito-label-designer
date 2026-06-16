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

    private string $lastPrintMethod = '';

    /** @var list<string> */
    private array $lastPrintOutput = [];

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

    public function getLastPrintMethod(): string
    {
        return $this->lastPrintMethod;
    }

    /**
     * @return list<string>
     */
    public function getLastPrintOutput(): array
    {
        return $this->lastPrintOutput;
    }

    /**
     * @return array{printers: list<string>, platform: string}
     */
    public function listPrintersInfo(): array
    {
        $printers = $this->listPrinters();
        $info = [
            'printers' => $printers,
            'platform' => PrinterPlatform::osFamily(),
        ];

        if ($printers === [] || filter_var(getenv('MOJITO_PRINTER_DEBUG'), FILTER_VALIDATE_BOOL)) {
            $info['diagnostics'] = PrinterPlatform::lastDiagnostics();
        }

        return $info;
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

        $tempDir = PrinterPlatform::writableTempDir(dirname($file));

        try {
            if (@file_put_contents($file, $zpl) === false) {
                throw new RuntimeException('Impossibile scrivere ZPL nel file temporaneo.');
            }

            if (PrinterPlatform::isWindows()) {
                $this->runWindowsPrint($this->printerName, $file, $tempDir);

                return;
            }

            $command = PrinterPlatform::buildPrintCommand($this->printerName, $file, $tempDir);
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

    private function runWindowsPrint(string $printerName, string $file, string $tempDir): void
    {
        $this->lastPrintMethod = '';
        $this->lastPrintOutput = [];
        $errors = [];

        foreach (PrinterPlatform::buildPrintCommands($printerName, $file, $tempDir) as $index => $command) {
            $result = $this->commandRunner->run($command);
            $method = $index === 0 ? 'raw-script' : 'print-exe';

            if ($result['code'] === 0) {
                $this->lastPrintMethod = $method;
                $this->lastPrintOutput = $result['output'];

                return;
            }

            $errors[] = $method.': '.implode("\n", $result['output']);
        }

        throw new RuntimeException(
            "Errore stampa etichetta (Windows/Laragon).\n"
            ."Verifica MOJITO_PRINT_TEMP=C:\\laragon\\tmp e che Apache Laragon giri come utente corrente.\n"
            .implode("\n---\n", $errors)
        );
    }

    private function createTempFile(): string|false
    {
        if ($this->tempFileFactory instanceof \Closure) {
            return ($this->tempFileFactory)();
        }

        $dir = PrinterPlatform::writableTempDir();

        return tempnam($dir, 'label_');
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
