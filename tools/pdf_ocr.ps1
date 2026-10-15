# tools/pdf_ocr.ps1 — text of a PDF without a text layer (scan / "Print To PDF" of an image), with the
# OCR built into Windows 10/11: Windows.Data.Pdf renders each page, Windows.Media.Ocr reads it.
# Used by followup_local_scan.php when pdftotext finds no text. Windows PowerShell 5.1 (WinRT types).
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File tools\pdf_ocr.ps1 -Pdf in.pdf -Out out.txt [-Lang en-US] [-Width 2400]
#
# Output: one OCR line per text line, pages separated by a form feed (as pdftotext). Exit 0 = read, 1 = error.
param([Parameter(Mandatory = $true)][string]$Pdf, [Parameter(Mandatory = $true)][string]$Out,
      [string]$Lang = 'en-US', [int]$Width = 2400)
$ErrorActionPreference = 'Stop'
try {
    Add-Type -AssemblyName System.Runtime.WindowsRuntime
    $null = [Windows.Storage.StorageFile, Windows.Storage, ContentType = WindowsRuntime]
    $null = [Windows.Data.Pdf.PdfDocument, Windows.Data.Pdf, ContentType = WindowsRuntime]
    $null = [Windows.Media.Ocr.OcrEngine, Windows.Foundation, ContentType = WindowsRuntime]
    $null = [Windows.Graphics.Imaging.BitmapDecoder, Windows.Graphics, ContentType = WindowsRuntime]
    $null = [Windows.Storage.Streams.InMemoryRandomAccessStream, Windows.Storage.Streams, ContentType = WindowsRuntime]

    # WinRT IAsyncOperation<T> / IAsyncAction → .NET Task, waited for.
    $asTaskOp = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
        $_.Name -eq 'AsTask' -and $_.GetParameters().Count -eq 1 -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncOperation`1' } | Select-Object -First 1
    $asTaskAct = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
        $_.Name -eq 'AsTask' -and $_.GetParameters().Count -eq 1 -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncAction' } | Select-Object -First 1
    function Await($op, [Type]$type) {
        $t = $asTaskOp.MakeGenericMethod($type).Invoke($null, @($op)); $t.Wait(-1) | Out-Null; $t.Result
    }
    function AwaitAction($op) { $t = $asTaskAct.Invoke($null, @($op)); $t.Wait(-1) | Out-Null }

    $engine = $null
    if ([Windows.Globalization.Language, Windows.Globalization, ContentType = WindowsRuntime]) {
        $l = New-Object Windows.Globalization.Language $Lang
        if ([Windows.Media.Ocr.OcrEngine]::IsLanguageSupported($l)) { $engine = [Windows.Media.Ocr.OcrEngine]::TryCreateFromLanguage($l) }
    }
    if (-not $engine) { $engine = [Windows.Media.Ocr.OcrEngine]::TryCreateFromUserProfileLanguages() }
    if (-not $engine) { throw 'No OCR language installed (Settings > Time & language > Language).' }

    $file = Await ([Windows.Storage.StorageFile]::GetFileFromPathAsync((Resolve-Path -LiteralPath $Pdf).Path)) ([Windows.Storage.StorageFile])
    $doc  = Await ([Windows.Data.Pdf.PdfDocument]::LoadFromFileAsync($file)) ([Windows.Data.Pdf.PdfDocument])
    $max  = [Windows.Media.Ocr.OcrEngine]::MaxImageDimension
    $pages = @()
    for ($i = 0; $i -lt $doc.PageCount; $i++) {
        $page = $doc.GetPage($i)
        $opts = New-Object Windows.Data.Pdf.PdfPageRenderOptions
        $w = [Math]::Min($Width, $max)
        $h = [Math]::Round($page.Size.Height * $w / [Math]::Max(1, $page.Size.Width))
        if ($h -gt $max) { $w = [Math]::Floor($w * $max / $h); $h = $max }
        $opts.DestinationWidth = [uint32]$w; $opts.DestinationHeight = [uint32]$h
        $stream = New-Object Windows.Storage.Streams.InMemoryRandomAccessStream
        AwaitAction ($page.RenderToStreamAsync($stream, $opts))
        $dec = Await ([Windows.Graphics.Imaging.BitmapDecoder]::CreateAsync($stream)) ([Windows.Graphics.Imaging.BitmapDecoder])
        $bmp = Await ($dec.GetSoftwareBitmapAsync()) ([Windows.Graphics.Imaging.SoftwareBitmap])
        $res = Await ($engine.RecognizeAsync($bmp)) ([Windows.Media.Ocr.OcrResult])
        $pages += (($res.Lines | ForEach-Object { $_.Text }) -join "`n")
        $page.Dispose(); $stream.Dispose()
    }
    [System.IO.File]::WriteAllText($Out, ($pages -join "`f`n"), (New-Object System.Text.UTF8Encoding $false))
    exit 0
} catch {
    [Console]::Error.WriteLine('pdf_ocr: ' + $_.Exception.Message)
    exit 1
}
