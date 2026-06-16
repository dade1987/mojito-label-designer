<?php

declare(strict_types=1);

namespace Mojito\Label;

use RuntimeException;

/**
 * Servizio di stampa etichette ZPL via CUPS (lp -o raw).
 * Compatibile con Citizen CL-S703Z in emulazione ZPL2.
 */
final class LabelPrinterService
{
    public const DEFAULT_PRINTER = 'Citizen_CL_S703Z';

    public function __construct(
        private readonly ZplBuilder $zplBuilder = new ZplBuilder,
        private readonly ShellCommandRunner $commandRunner = new ShellCommandRunner,
        private string $printerName = self::DEFAULT_PRINTER,
        private readonly ?\Closure $tempFileFactory = null,
    ) {}

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
        $file = $this->createTempFile();

        if ($file === false) {
            throw new RuntimeException('Impossibile creare file temporaneo per la stampa.');
        }

        try {
            if (@file_put_contents($file, $zpl) === false) {
                throw new RuntimeException('Impossibile scrivere ZPL nel file temporaneo.');
            }

            $command = 'lp -d '.escapeshellarg($this->printerName).' -o raw '.escapeshellarg($file);
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
        $result = $this->commandRunner->run('lpstat -p');

        if ($result['code'] !== 0) {
            return [self::DEFAULT_PRINTER];
        }

        $printers = [];

        foreach ($result['output'] as $line) {
            if (preg_match('/^printer\s+(\S+)/', $line, $matches) === 1) {
                $printers[] = $matches[1];
            }
        }

        return $printers !== [] ? $printers : [self::DEFAULT_PRINTER];
    }
}
