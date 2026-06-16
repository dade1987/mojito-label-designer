<?php

declare(strict_types=1);

namespace Mojito\Label;

final class PrinterPlatform
{
    public const DEFAULT_PRINTER = 'Citizen_CL_S703Z';

    public static function osFamily(): string
    {
        return PHP_OS_FAMILY;
    }

    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * @return list<string>
     */
    public static function listPrinters(ShellCommandRunner $runner): array
    {
        return self::isWindows()
            ? self::listWindowsPrinters($runner)
            : self::listUnixPrinters($runner);
    }

    public static function buildPrintCommand(string $printerName, string $filePath): string
    {
        if (self::isWindows()) {
            $script = self::windowsPrintScriptPath();

            return 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File '
                .escapeshellarg($script)
                .' -PrinterName '.escapeshellarg($printerName)
                .' -FilePath '.escapeshellarg($filePath);
        }

        return 'lp -d '.escapeshellarg($printerName).' -o raw '.escapeshellarg($filePath);
    }

    public static function defaultPrinterName(): string
    {
        $fromEnv = getenv('MOJITO_DEFAULT_PRINTER');

        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return trim($fromEnv);
        }

        return self::DEFAULT_PRINTER;
    }

    public static function windowsPrintScriptPath(): string
    {
        return dirname(__DIR__).'/bin/print-raw.ps1';
    }

    /**
     * @return list<string>
     */
    private static function listWindowsPrinters(ShellCommandRunner $runner): array
    {
        $result = $runner->run(
            'powershell -NoProfile -NonInteractive -Command "(Get-Printer | Select-Object -ExpandProperty Name) -join [char]10"'
        );

        if ($result['code'] === 0) {
            $names = self::parseWindowsLineOutput($result['output']);

            if ($names !== []) {
                return $names;
            }
        }

        $fallback = $runner->run('wmic printer get Name /VALUE');

        return self::parseWmicPrinterOutput($fallback['output']);
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    public static function parseWindowsLineOutput(array $lines): array
    {
        return self::normalizeNames($lines);
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    public static function parseWmicPrinterOutput(array $lines): array
    {
        $names = [];

        foreach ($lines as $line) {
            if (preg_match('/^Name=(.+)$/i', trim($line), $matches) === 1) {
                $name = trim($matches[1]);

                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return self::uniqueNames($names);
    }

    /**
     * @return list<string>
     */
    private static function listUnixPrinters(ShellCommandRunner $runner): array
    {
        $names = [];

        $accepting = $runner->run('LANG=C lpstat -a 2>/dev/null');
        if ($accepting['code'] === 0) {
            foreach ($accepting['output'] as $line) {
                if (preg_match('/^(\S+)\s+accepting\b/i', trim($line), $matches) === 1) {
                    $names[] = $matches[1];
                }
            }
        }

        $printers = $runner->run('LANG=C lpstat -p 2>/dev/null');
        if ($printers['code'] === 0) {
            foreach ($printers['output'] as $line) {
                if (preg_match('/^printer\s+(\S+)/i', trim($line), $matches) === 1) {
                    $names[] = $matches[1];
                }
            }
        }

        $destinations = $runner->run('LANG=C lpstat -e 2>/dev/null');
        if ($destinations['code'] === 0) {
            foreach ($destinations['output'] as $line) {
                $candidate = trim($line);

                if ($candidate !== '' && ! str_starts_with($candidate, 'lpstat')) {
                    $names[] = $candidate;
                }
            }
        }

        return self::uniqueNames($names);
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function normalizeNames(array $lines): array
    {
        $names = [];

        foreach ($lines as $line) {
            foreach (preg_split('/\R/u', $line) ?: [] as $part) {
                $name = trim($part);

                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return self::uniqueNames($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function uniqueNames(array $names): array
    {
        $unique = [];

        foreach ($names as $name) {
            if (! in_array($name, $unique, true)) {
                $unique[] = $name;
            }
        }

        return $unique;
    }
}
