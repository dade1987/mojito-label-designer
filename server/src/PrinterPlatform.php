<?php

declare(strict_types=1);

namespace Mojito\Label;

final class PrinterPlatform
{
    public const DEFAULT_PRINTER = 'Citizen_CL_S703Z';

    /** @var list<array{method: string, code: int, output: string}> */
    private static array $lastDiagnostics = [];

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
        self::$lastDiagnostics = [];

        return self::isWindows()
            ? self::listWindowsPrinters($runner)
            : self::listUnixPrinters($runner);
    }

    /**
     * @return list<array{method: string, code: int, output: string}>
     */
    public static function lastDiagnostics(): array
    {
        return self::$lastDiagnostics;
    }

    public static function buildPrintCommand(string $printerName, string $filePath): string
    {
        if (self::isWindows()) {
            $script = self::windowsPrintScriptPath();
            $powershell = self::windowsPowerShellExecutable();

            return escapeshellarg($powershell)
                .' -NoProfile -NonInteractive -ExecutionPolicy Bypass -File '
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

    public static function windowsPowerShellExecutable(): string
    {
        $systemRoot = getenv('SystemRoot') ?: 'C:\\Windows';
        $candidates = [
            $systemRoot.'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe',
            $systemRoot.'\\Sysnative\\WindowsPowerShell\\v1.0\\powershell.exe',
            'powershell.exe',
            'powershell',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === 'powershell.exe' || $candidate === 'powershell' || is_file($candidate)) {
                return $candidate;
            }
        }

        return 'powershell.exe';
    }

    /**
     * @return list<string>
     */
    private static function listWindowsPrinters(ShellCommandRunner $runner): array
    {
        $strategies = [
            'com-wmi' => static fn (): array => self::listWindowsPrintersViaCom(),
            'powershell-win32-printer' => static fn (): array => self::listViaPowerShell(
                $runner,
                'Get-CimInstance -ClassName Win32_Printer | Select-Object -ExpandProperty Name'
            ),
            'powershell-get-printer' => static fn (): array => self::listViaPowerShell(
                $runner,
                'Get-Printer | Select-Object -ExpandProperty Name'
            ),
            'wmic-win32-printer' => static fn (): array => self::listViaCommand(
                $runner,
                'wmic path Win32_Printer get Name',
                static fn (array $output): array => self::parseWmicTableOutput($output)
            ),
            'reg-print-printers' => static fn (): array => self::listViaCommand(
                $runner,
                'reg query "HKLM\\SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion\\Print\\Printers"',
                static fn (array $output): array => self::parseRegistryPrinterOutput($output)
            ),
            'wmic-printer-value' => static fn (): array => self::listViaCommand(
                $runner,
                'wmic printer get Name /VALUE',
                static fn (array $output): array => self::parseWmicPrinterOutput($output)
            ),
        ];

        foreach ($strategies as $method => $strategy) {
            $names = $strategy();

            self::recordDiagnostic($method, $names === [] ? 1 : 0, $names !== [] ? implode(', ', $names) : 'empty');

            if ($names !== []) {
                return $names;
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function listWindowsPrintersViaCom(): array
    {
        if (! class_exists('COM')) {
            self::recordDiagnostic('com-wmi', 127, 'COM extension not available');

            return [];
        }

        try {
            $wmi = new \COM('winmgmts:{impersonationLevel=impersonate}!\\\\.\\root\\cimv2');
            $printers = $wmi->ExecQuery('SELECT Name FROM Win32_Printer');
            $names = [];

            foreach ($printers as $printer) {
                $name = trim((string) $printer->Name);

                if ($name !== '') {
                    $names[] = $name;
                }
            }

            return self::uniqueNames($names);
        } catch (\Throwable $exception) {
            self::recordDiagnostic('com-wmi', 1, $exception->getMessage());

            return [];
        }
    }

    /**
     * @return list<string>
     */
    private static function listViaPowerShell(ShellCommandRunner $runner, string $script): array
    {
        $powershell = self::windowsPowerShellExecutable();
        $command = escapeshellarg($powershell)
            .' -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command '
            .escapeshellarg($script.' | ForEach-Object { $_.ToString().Trim() } | Where-Object { $_ -ne "" }');

        $result = $runner->run($command);

        if ($result['code'] !== 0) {
            self::recordDiagnostic('powershell', $result['code'], self::summarizeOutput($result['output']));

            return [];
        }

        return self::parseWindowsLineOutput($result['output']);
    }

    /**
     * @param  callable(list<string>): list<string>  $parser
     * @return list<string>
     */
    private static function listViaCommand(ShellCommandRunner $runner, string $command, callable $parser): array
    {
        $result = $runner->run($command);

        if ($result['code'] !== 0) {
            self::recordDiagnostic($command, $result['code'], self::summarizeOutput($result['output']));

            return [];
        }

        return $parser($result['output']);
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
    public static function parseWmicTableOutput(array $lines): array
    {
        $names = [];

        foreach ($lines as $line) {
            $candidate = trim($line);

            if ($candidate === '' || strcasecmp($candidate, 'Name') === 0) {
                continue;
            }

            $names[] = $candidate;
        }

        return self::uniqueNames($names);
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
     * @param  list<string>  $lines
     * @return list<string>
     */
    public static function parseRegistryPrinterOutput(array $lines): array
    {
        $names = [];

        foreach ($lines as $line) {
            if (preg_match('/\\\\Printers\\\\([^\\\\]+)\s*$/i', trim($line), $matches) === 1) {
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

    /**
     * @param  list<string>  $output
     */
    private static function summarizeOutput(array $output): string
    {
        $text = trim(implode("\n", $output));

        if ($text === '') {
            return 'empty output';
        }

        return strlen($text) > 240 ? substr($text, 0, 240).'…' : $text;
    }

    private static function recordDiagnostic(string $method, int $code, string $output): void
    {
        self::$lastDiagnostics[] = [
            'method' => $method,
            'code' => $code,
            'output' => $output,
        ];
    }
}
