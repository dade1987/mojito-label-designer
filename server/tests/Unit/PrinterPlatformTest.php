<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use Mojito\Label\PrinterPlatform;
use Mojito\Label\ShellCommandRunner;
use PHPUnit\Framework\TestCase;

final class PrinterPlatformTest extends TestCase
{
    public function test_list_unix_printers_from_lpstat_accepting(): void
    {
        $runner = new ShellCommandRunner(function (string $command): array {
            if (str_contains($command, 'lpstat -a')) {
                return ['output' => ['Citizen_CL_S703Z accepting requests since Mon 01 Jan 2024'], 'code' => 0];
            }

            return ['output' => [], 'code' => 1];
        });

        $this->assertSame(['Citizen_CL_S703Z'], PrinterPlatform::listPrinters($runner));
    }

    public function test_list_unix_printers_from_lpstat_p_when_accepting_empty(): void
    {
        $runner = new ShellCommandRunner(function (string $command): array {
            if (str_contains($command, 'lpstat -a')) {
                return ['output' => [], 'code' => 1];
            }

            if (str_contains($command, 'lpstat -p')) {
                return ['output' => ['printer Zebra_ZD420 is idle.'], 'code' => 0];
            }

            return ['output' => [], 'code' => 1];
        });

        $this->assertSame(['Zebra_ZD420'], PrinterPlatform::listPrinters($runner));
    }

    public function test_parse_windows_line_output(): void
    {
        $this->assertSame(
            ['Citizen CL-S703', 'Microsoft Print to PDF'],
            PrinterPlatform::parseWindowsLineOutput(["Citizen CL-S703\nMicrosoft Print to PDF"])
        );
    }

    public function test_parse_wmic_table_output(): void
    {
        $this->assertSame(
            ['Citizen CL-S703', 'Microsoft Print to PDF'],
            PrinterPlatform::parseWmicTableOutput([
                'Name',
                '',
                'Citizen CL-S703  ',
                'Microsoft Print to PDF',
            ])
        );
    }

    public function test_parse_registry_printer_output(): void
    {
        $this->assertSame(
            ['Citizen CL-S703', 'Microsoft Print to PDF'],
            PrinterPlatform::parseRegistryPrinterOutput([
                'HKEY_LOCAL_MACHINE\\SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion\\Print\\Printers\\Citizen CL-S703',
                'HKEY_LOCAL_MACHINE\\SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion\\Print\\Printers\\Microsoft Print to PDF',
            ])
        );
    }

    public function test_parse_wmic_printer_output(): void
    {
        $this->assertSame(
            ['Citizen CL-S703', 'PDF Printer'],
            PrinterPlatform::parseWmicPrinterOutput([
                'Name=Citizen CL-S703',
                '',
                'Name=PDF Printer',
            ])
        );
    }

    public function test_windows_powershell_executable_prefers_system_path(): void
    {
        $path = PrinterPlatform::windowsPowerShellExecutable();
        $this->assertNotSame('', $path);
        $this->assertStringContainsString('powershell', strtolower($path));
    }

    public function test_list_returns_empty_when_no_printers_found(): void
    {
        $runner = new ShellCommandRunner(static fn (): array => ['output' => [], 'code' => 1]);

        $this->assertSame([], PrinterPlatform::listPrinters($runner));
    }

    public function test_build_print_command_uses_platform_backend(): void
    {
        $command = PrinterPlatform::buildPrintCommand('Citizen CL-S703', '/tmp/label.zpl');

        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertStringContainsString('powershell', $command);
            $this->assertStringContainsString('print-raw.ps1', $command);
        } else {
            $this->assertStringContainsString('lp -d', $command);
            $this->assertStringContainsString('-o raw', $command);
        }
    }

    public function test_default_printer_name_from_env(): void
    {
        putenv('MOJITO_DEFAULT_PRINTER=My_Windows_Printer');
        $this->assertSame('My_Windows_Printer', PrinterPlatform::defaultPrinterName());
        putenv('MOJITO_DEFAULT_PRINTER');
    }
}
