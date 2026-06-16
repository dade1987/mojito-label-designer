# Invia ZPL in modalita RAW a una stampante Windows (Winspool API).
param(
    [Parameter(Mandatory = $true)][string]$PrinterName,
    [Parameter(Mandatory = $true)][string]$FilePath,
    [string]$TempDir = ''
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $FilePath)) {
    Write-Error "File non trovato: $FilePath"
    exit 1
}

$workTemp = if ($TempDir -ne '') { $TempDir } else { Split-Path -Parent $FilePath }
if (-not (Test-Path -LiteralPath $workTemp)) {
    New-Item -ItemType Directory -Force -Path $workTemp | Out-Null
}

$env:TEMP = $workTemp
$env:TMP = $workTemp

if (-not ("MojitoRawPrinter" -as [type])) {
    Add-Type -TypeDefinition @"
using System;
using System.Runtime.InteropServices;

public class MojitoRawPrinter
{
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Ansi)]
    public class DOCINFOA
    {
        [MarshalAs(UnmanagedType.LPStr)] public string pDocName;
        [MarshalAs(UnmanagedType.LPStr)] public string pOutputFile;
        [MarshalAs(UnmanagedType.LPStr)] public string pDataType;
    }

    [DllImport("winspool.drv", CharSet = CharSet.Ansi, SetLastError = true)]
    public static extern bool OpenPrinter(string szPrinter, out IntPtr hPrinter, IntPtr pd);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool ClosePrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", CharSet = CharSet.Ansi, SetLastError = true)]
    public static extern bool StartDocPrinter(IntPtr hPrinter, int level, [In] DOCINFOA di);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool EndDocPrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool StartPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool EndPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool WritePrinter(IntPtr hPrinter, IntPtr pBytes, int dwCount, out int dwWritten);

    public static bool SendBytes(string printerName, byte[] bytes)
    {
        IntPtr hPrinter;
        if (!OpenPrinter(printerName, out hPrinter, IntPtr.Zero))
        {
            return false;
        }

        try
        {
            var docInfo = new DOCINFOA
            {
                pDocName = "Mojito ZPL",
                pDataType = "RAW",
            };

            if (!StartDocPrinter(hPrinter, 1, docInfo))
            {
                return false;
            }

            StartPagePrinter(hPrinter);
            IntPtr unmanagedBytes = Marshal.AllocCoTaskMem(bytes.Length);
            Marshal.Copy(bytes, 0, unmanagedBytes, bytes.Length);
            int written;
            var ok = WritePrinter(hPrinter, unmanagedBytes, bytes.Length, out written);
            Marshal.FreeCoTaskMem(unmanagedBytes);
            EndPagePrinter(hPrinter);
            EndDocPrinter(hPrinter);

            return ok && written == bytes.Length;
        }
        finally
        {
            ClosePrinter(hPrinter);
        }
    }
}
"@
}

$bytes = [System.IO.File]::ReadAllBytes($FilePath)

if (-not [MojitoRawPrinter]::SendBytes($PrinterName, $bytes)) {
    Write-Error "Invio RAW fallito verso '$PrinterName'"
    exit 1
}

exit 0
