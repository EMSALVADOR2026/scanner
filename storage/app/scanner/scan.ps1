param (
    [string]$OutputPath = ".",
    [string]$ResultFile = ""
)

[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$OutputEncoding = [System.Text.Encoding]::UTF8

trap {
    $msg      = "ERROR: Linea $($_.InvocationInfo.ScriptLineNumber) - $($_.Exception.Message)"
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    if ($ResultFile -ne "") {
        [System.IO.File]::WriteAllText($ResultFile, $msg, $utf8NoBom)
    }
    exit 1
}

function Write-Result([string]$msg) {
    if ($ResultFile -ne "") {
        [System.IO.File]::WriteAllText($ResultFile, $msg, [System.Text.Encoding]::UTF8)
    }
    Write-Output $msg
}

function Write-Bytes {
    param([System.IO.FileStream]$Stream, [byte[]]$Data)
    $Stream.Write($Data, 0, $Data.Length)
    return [int]$Data.Length
}

function Write-Text {
    param([System.IO.FileStream]$Stream, [string]$Text)
    $encoding = [System.Text.Encoding]::GetEncoding(1252)
    $bytes    = $encoding.GetBytes($Text)
    $Stream.Write($bytes, 0, $bytes.Length)
    return [int]$bytes.Length
}

Add-Type -AssemblyName System.Drawing

try {
    if (-not (Test-Path $OutputPath)) {
        New-Item -ItemType Directory -Force -Path $OutputPath | Out-Null
    }

    # 1. Conectar escaner
    $deviceManager = New-Object -ComObject WIA.DeviceManager
    $device        = $null

    foreach ($info in $deviceManager.DeviceInfos) {
        if ($info.Type -eq 1) {
            $device = $info.Connect()
            break
        }
    }

    if ($null -eq $device) {
        Write-Result "ERROR: No se encontro ningun escaner conectado"
        exit 1
    }

    $item = $device.Items(1)

    if ($null -eq $item) {
        Write-Result "ERROR: No se pudo obtener el item del escaner"
        exit 1
    }

    # 2. Configurar propiedades
    try { $item.Properties("3088").Value = 4   } catch {}
    try { $item.Properties("6146").Value = 4   } catch {}
    try { $item.Properties("6147").Value = 300 } catch {}
    try { $item.Properties("6148").Value = 300 } catch {}

    # 3. Carpeta temporal
    $tempFolder = Join-Path $OutputPath "temp_pages"
    if (Test-Path $tempFolder) { Remove-Item $tempFolder -Recurse -Force }
    New-Item -ItemType Directory -Force -Path $tempFolder | Out-Null

    $bmpFiles = [System.Collections.Generic.List[string]]::new()
    $pageNum  = 1

    # 4. Escanea hoja por hoja
    while ($true) {
        try {
            $wiaFormatBMP = "{B96B3CAE-0728-11D3-9D7B-0000F81EF32E}"
            $image        = $item.Transfer($wiaFormatBMP)
            if ($null -eq $image) { break }

            $bmpFile = Join-Path $tempFolder ("page_{0:D4}.bmp" -f $pageNum)
            $image.SaveFile($bmpFile)

            if (Test-Path $bmpFile) {
                $bmpFiles.Add($bmpFile)
                $pageNum++
            }
            Start-Sleep -Milliseconds 300
        } catch {
            break
        }
    }

    if ($bmpFiles.Count -eq 0) {
        Write-Result "ERROR: No se escaneo ninguna hoja"
        exit 1
    }

    # 5. Convierte BMP a JPEG en disco
    $jpegFiles = [System.Collections.Generic.List[string]]::new()
    $widths    = [System.Collections.Generic.List[int]]::new()
    $heights   = [System.Collections.Generic.List[int]]::new()
    $dpiXList  = [System.Collections.Generic.List[double]]::new()
    $dpiYList  = [System.Collections.Generic.List[double]]::new()

    $enc = [System.Drawing.Imaging.ImageCodecInfo]::GetImageEncoders() |
           Where-Object { $_.MimeType -eq 'image/jpeg' } |
           Select-Object -First 1

    if ($null -eq $enc) {
        Write-Result "ERROR: No se encontro encoder JPEG"
        exit 1
    }

    $ep = New-Object System.Drawing.Imaging.EncoderParameters(1)
    $ep.Param[0] = New-Object System.Drawing.Imaging.EncoderParameter(
        [System.Drawing.Imaging.Encoder]::Quality, [long]90
    )

    for ($i = 0; $i -lt $bmpFiles.Count; $i++) {
        $img      = [System.Drawing.Image]::FromFile($bmpFiles[$i])
        $jpegFile = Join-Path $tempFolder ("page_{0:D4}.jpg" -f ($i + 1))

        $w = $img.Width
        $h = $img.Height

        if ($img.HorizontalResolution -gt 0) {
            $dx = [double]$img.HorizontalResolution
        } else {
            $dx = [double]300
        }

        if ($img.VerticalResolution -gt 0) {
            $dy = [double]$img.VerticalResolution
        } else {
            $dy = [double]300
        }

        $widths.Add($w)
        $heights.Add($h)
        $dpiXList.Add($dx)
        $dpiYList.Add($dy)

        $img.Save($jpegFile, $enc, $ep)
        $img.Dispose()

        if (-not (Test-Path $jpegFile)) {
            Write-Result "ERROR: No se pudo guardar JPEG pagina $($i + 1)"
            exit 1
        }
        $jpegFiles.Add($jpegFile)
    }

    # 6. Construye el PDF
    $timestamp  = Get-Date -Format "yyyyMMdd_HHmmss"
    $outputFile = Join-Path $OutputPath "scan_$timestamp.pdf"
    $numPages   = $jpegFiles.Count

    $imgObjs     = [int[]]::new($numPages)
    $contentObjs = [int[]]::new($numPages)
    $pageObjs    = [int[]]::new($numPages)

    for ($i = 0; $i -lt $numPages; $i++) {
        $imgObjs[$i]     = 3 + $i * 3
        $contentObjs[$i] = 4 + $i * 3
        $pageObjs[$i]    = 5 + $i * 3
    }

    $maxObjNum = 2 + $numPages * 3
    $offsets   = [long[]]::new($maxObjNum + 1)
    [long]$pos = 0

    $fs = [System.IO.File]::Open($outputFile, [System.IO.FileMode]::Create)

    $pos += Write-Text $fs "%PDF-1.4`n"

    for ($i = 0; $i -lt $numPages; $i++) {
        $jpegData = [System.IO.File]::ReadAllBytes($jpegFiles[$i])
        $wPx      = $widths[$i]
        $hPx      = $heights[$i]
        $wPt      = [math]::Round($wPx * 72.0 / $dpiXList[$i], 3)
        $hPt      = [math]::Round($hPx * 72.0 / $dpiYList[$i], 3)

        $imgN  = $imgObjs[$i]
        $contN = $contentObjs[$i]
        $pageN = $pageObjs[$i]

        # Objeto imagen
        $offsets[$imgN] = $pos
        $pos += Write-Text $fs "$imgN 0 obj`n"
        $pos += Write-Text $fs "<< /Type /XObject /Subtype /Image /Width $wPx /Height $hPx /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length $($jpegData.Length) >>`n"
        $pos += Write-Text $fs "stream`n"
        $pos += Write-Bytes $fs $jpegData
        $pos += Write-Text $fs "`nendstream`nendobj`n"

        # Stream contenido
        $contentStr   = "q $wPt 0 0 $hPt 0 0 cm /Im$i Do Q"
        $contentBytes = [System.Text.Encoding]::GetEncoding(1252).GetBytes($contentStr)

        $offsets[$contN] = $pos
        $pos += Write-Text $fs "$contN 0 obj`n"
        $pos += Write-Text $fs "<< /Length $($contentBytes.Length) >>`n"
        $pos += Write-Text $fs "stream`n"
        $pos += Write-Bytes $fs $contentBytes
        $pos += Write-Text $fs "`nendstream`nendobj`n"

        # Objeto pagina
        $offsets[$pageN] = $pos
        $pos += Write-Text $fs "$pageN 0 obj`n"
        $pos += Write-Text $fs "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $wPt $hPt] /Resources << /XObject << /Im$i $imgN 0 R >> >> /Contents $contN 0 R >>`n"
        $pos += Write-Text $fs "endobj`n"
    }

    # Objeto Pages
    $offsets[2] = $pos
    $kidsStr    = ($pageObjs | ForEach-Object { "$_ 0 R" }) -join " "
    $pos += Write-Text $fs "2 0 obj`n"
    $pos += Write-Text $fs "<< /Type /Pages /Kids [$kidsStr] /Count $numPages >>`n"
    $pos += Write-Text $fs "endobj`n"

    # Objeto Catalog
    $offsets[1] = $pos
    $pos += Write-Text $fs "1 0 obj`n"
    $pos += Write-Text $fs "<< /Type /Catalog /Pages 2 0 R >>`n"
    $pos += Write-Text $fs "endobj`n"

    # xref
    $xrefPos = $pos
    $pos += Write-Text $fs "xref`n"
    $pos += Write-Text $fs "0 $($maxObjNum + 1)`n"
    $pos += Write-Text $fs "0000000000 65535 f `n"

    for ($n = 1; $n -le $maxObjNum; $n++) {
        $pos += Write-Text $fs ("{0:D10} 00000 n `n" -f [long]$offsets[$n])
    }

    $pos += Write-Text $fs "trailer`n"
    $pos += Write-Text $fs "<< /Size $($maxObjNum + 1) /Root 1 0 R >>`n"
    $pos += Write-Text $fs "startxref`n$xrefPos`n"
    $pos += Write-Text $fs "%%EOF`n"

    $fs.Flush()
    $fs.Close()
    $fs.Dispose()

    # 7. Limpia temporales
    Remove-Item $tempFolder -Recurse -Force

    if (Test-Path $outputFile) {
        Write-Result "OK:$outputFile"
        exit 0
    } else {
        Write-Result "ERROR: No se genero el PDF"
        exit 1
    }

} catch {
    if ($null -ne $fs) { try { $fs.Close() } catch {} }
    Write-Result "ERROR: Linea $($_.InvocationInfo.ScriptLineNumber) - $($_.Exception.Message)"
    exit 1
}