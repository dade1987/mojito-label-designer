# Stampa un'etichetta gia' disegnata (PNG) su una stampante Windows usando il
# driver installato: e' la strada per le stampanti che non parlano ZPL.
#
# A differenza di print-raw.ps1 qui NON si manda niente in RAW: si costruisce
# un documento di stampa della misura esatta dell'etichetta e ci si disegna
# dentro l'immagine, senza margini e senza riscalature del driver.
param(
    [Parameter(Mandatory = $true)][string]$PrinterName,
    [Parameter(Mandatory = $true)][string]$FilePath,
    [Parameter(Mandatory = $true)][double]$WidthMm,
    [Parameter(Mandatory = $true)][double]$HeightMm,
    [int]$Copies = 1,
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

Add-Type -AssemblyName System.Drawing

# PrintDocument ragiona in centesimi di pollice.
$widthHundredths = [int][Math]::Round($WidthMm / 25.4 * 100)
$heightHundredths = [int][Math]::Round($HeightMm / 25.4 * 100)

if ($widthHundredths -lt 1 -or $heightHundredths -lt 1) {
    Write-Error "Misura etichetta non valida: ${WidthMm}x${HeightMm} mm"
    exit 1
}

$image = [System.Drawing.Image]::FromFile($FilePath)

try {
    $document = New-Object System.Drawing.Printing.PrintDocument
    $document.PrinterSettings.PrinterName = $PrinterName

    if (-not $document.PrinterSettings.IsValid) {
        Write-Error "Stampante non trovata: $PrinterName"
        exit 1
    }

    $document.DocumentName = 'Mojito label'
    $document.PrinterSettings.Copies = [int][Math]::Max(1, $Copies)
    $document.DefaultPageSettings.PaperSize = New-Object System.Drawing.Printing.PaperSize('MojitoLabel', $widthHundredths, $heightHundredths)
    $document.DefaultPageSettings.Margins = New-Object System.Drawing.Printing.Margins(0, 0, 0, 0)
    $document.DefaultPageSettings.Landscape = $false
    $document.OriginAtMargins = $false

    $handler = {
        param($sender, $e)
        # L'etichetta occupa tutta la pagina: la pagina e' l'etichetta.
        $target = New-Object System.Drawing.Rectangle(0, 0, $e.PageBounds.Width, $e.PageBounds.Height)
        $e.Graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::NearestNeighbor
        $e.Graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::Half
        $e.Graphics.DrawImage($image, $target)
        $e.HasMorePages = $false
    }

    $document.add_PrintPage($handler)
    $document.Print()
    Write-Output "Stampato $FilePath su $PrinterName ($WidthMm x $HeightMm mm)"
    exit 0
} finally {
    $image.Dispose()
}
