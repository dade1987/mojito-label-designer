<?php

declare(strict_types=1);

namespace Mojito\Label;

use InvalidArgumentException;
use RuntimeException;

/**
 * Servizio di stampa etichette ZPL (CUPS su Linux, Winspool RAW su Windows).
 * Compatibile con Citizen CL-S700 in emulazione ZPL2.
 */
final class LabelPrinterService
{
    /** @deprecated Usare PrinterPlatform::DEFAULT_PRINTER */
    public const DEFAULT_PRINTER = PrinterPlatform::DEFAULT_PRINTER;

    /** Etichetta descritta in ZPL e mandata alla stampante in RAW. */
    public const MODE_ZPL = 'zpl';

    /** Etichetta disegnata qui e mandata alla coda di stampa come immagine. */
    public const MODE_GRAPHIC = 'graphic';

    /** Oltre questo numero non è più una stampa: è un incidente. */
    public const MAX_COPIES = 1000;

    private string $lastPrintMethod = '';

    /** @var list<string> */
    private array $lastPrintOutput = [];

    public function __construct(
        private readonly ZplBuilder $zplBuilder = new ZplBuilder,
        private readonly ShellCommandRunner $commandRunner = new ShellCommandRunner,
        private string $printerName = '',
        private readonly ?\Closure $tempFileFactory = null,
        private readonly LabelRasterRenderer $rasterRenderer = new LabelRasterRenderer,
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
        // La risoluzione di ogni stampante, dove si puo' sapere: disegnare a
        // 203 dpi cio' che verra' stampato a 300 produce etichette di misura
        // sbagliata, e il designer non ha modo di accorgersene da solo.
        $resolutions = [];
        foreach ($printers as $printer) {
            $dpi = PrinterResolution::forPrinter($printer);

            if ($dpi !== null) {
                $resolutions[$printer] = $dpi;
            }
        }

        $info = [
            'printers' => $printers,
            'printerResolutions' => $resolutions,
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
        $this->printJob($data, 1);
    }

    /**
     * Stampa una etichetta in più copie, con la strada che il layout chiede.
     *
     * @param  array<string, mixed>  $data
     */
    public function printJob(array $data, int $copies = 1): void
    {
        if ($copies > self::MAX_COPIES) {
            throw new RuntimeException('Troppe copie richieste: il massimo è '.self::MAX_COPIES.'.');
        }

        $copies = max(1, $copies);

        if ($this->resolvePrintMode($data) === self::MODE_GRAPHIC) {
            $png = $this->renderPng($data);
            $media = LabelMedia::fromTemplate($this->templateOf($data));

            for ($copy = 0; $copy < $copies; $copy++) {
                $this->printPng($png, $media);
            }

            return;
        }

        $zpl = $this->buildZpl($data);

        for ($copy = 0; $copy < $copies; $copy++) {
            $this->printZpl($zpl);
        }
    }

    /**
     * La strada di stampa chiesta: quella della richiesta, se non c'è quella
     * del layout, altrimenti ZPL come è sempre stato.
     *
     * @param  array<string, mixed>  $data
     */
    public function resolvePrintMode(array $data): string
    {
        $template = $this->templateOf($data);
        $mode = TypeCaster::string($data['printMode'] ?? $template['printMode'] ?? self::MODE_ZPL, self::MODE_ZPL);

        return strtolower(trim($mode)) === self::MODE_GRAPHIC ? self::MODE_GRAPHIC : self::MODE_ZPL;
    }

    /**
     * L'etichetta disegnata, senza stamparla: serve all'anteprima e alla
     * stampa grafica.
     *
     * @param  array<string, mixed>  $data
     */
    public function renderPng(array $data): string
    {
        $template = $this->templateOf($data);

        if ($template === []) {
            throw new InvalidArgumentException('Per la stampa grafica serve un layout: manca il campo template.');
        }

        $values = isset($data['values']) && is_array($data['values'])
            ? TypeCaster::stringKeyedArray($data['values'])
            : [];

        return $this->rasterRenderer->renderPng($template, $values);
    }

    /**
     * Manda alla coda di stampa un'etichetta già disegnata.
     */
    public function printPng(string $png, LabelMedia $media): void
    {
        if (trim($this->printerName) === '') {
            throw new RuntimeException('Nessuna stampante selezionata.');
        }

        $file = $this->createTempFile();

        if ($file === false) {
            throw new RuntimeException('Impossibile creare file temporaneo per la stampa.');
        }

        // Il driver di stampa sceglie il filtro dall'estensione: senza ".png"
        // CUPS tratta l'immagine come testo e sputa fuori pagine di byte.
        $imageFile = $file.'.png';
        $tempDir = PrinterPlatform::writableTempDir(dirname($file));

        try {
            if (@file_put_contents($imageFile, $png) === false) {
                throw new RuntimeException('Impossibile scrivere l\'immagine dell\'etichetta nel file temporaneo.');
            }

            $errors = [];

            foreach (PrinterPlatform::buildGraphicPrintCommands($this->printerName, $imageFile, $media, $tempDir) as $command) {
                $result = $this->commandRunner->run($command);

                if ($result['code'] === 0) {
                    $this->lastPrintMethod = 'graphic';
                    $this->lastPrintOutput = $result['output'];

                    return;
                }

                $errors[] = implode("\n", $result['output']);
            }

            throw new RuntimeException('Errore stampa etichetta (grafica): '.implode("\n---\n", $errors));
        } finally {
            if (is_file($imageFile)) {
                unlink($imageFile);
            }

            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function templateOf(array $data): array
    {
        return isset($data['template']) && is_array($data['template'])
            ? TypeCaster::stringKeyedArray($data['template'])
            : [];
    }

    /**
     * @return list<string>
     */
    public function listPrinters(): array
    {
        return PrinterPlatform::listPrinters($this->commandRunner);
    }
}
