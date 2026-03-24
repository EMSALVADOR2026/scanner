param (
    [string]$ServerUrl = "https://scanner-3-nuyr.onrender.com/",
    [string]$Token     = "P3uGVs0EIomc9Pn40yuGjMJL4KPUd9ZIaKolxak80OBJ24f30AgI4P5LBMCQxAjT"
)

[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$OutputEncoding = [System.Text.Encoding]::UTF8

$Headers = @{
    "X-Scanner-Token" = $Token
    "Accept"          = "application/json"
}

Add-Type -AssemblyName System.Drawing

# ── Funciones HTTP ────────────────────────────────────────────────────────────
function Get-Pending {
    try {
        return Invoke-RestMethod `
            -Uri "$ServerUrl/scanner/pending" `
            -Method GET `
            -Headers $Headers `
            -TimeoutSec 5
    } catch { return $null }
}

function Send-Status {
    param([string]$ScanId, [string]$Status, [string]$Message = "")
    try {
        $body = @{ scan_id = $ScanId; status = $Status; message = $Message } | ConvertTo-Json
        Invoke-RestMethod `
            -Uri "$ServerUrl/scanner/update-status" `
            -Method POST `
            -Headers ($Headers + @{ "Content-Type" = "application/json" }) `
            -Body $body `
            -TimeoutSec 5 | Out-Null
    } catch {}
}

function Send-Ping {
    try {
        Invoke-RestMethod `
            -Uri "$ServerUrl/scanner/agent-ping" `
            -Method POST `
            -Headers $Headers `
            -TimeoutSec 5 | Out-Null
    } catch {}
}

function Send-PDF {
    param([string]$ScanId, [string]$PdfPath)

    try {
        $pdfBytes = [System.IO.File]::ReadAllBytes($PdfPath)
        $filename = "documento.pdf"
        $boundary = "----FormBoundary$([System.Guid]::NewGuid().ToString('N'))"

        $bodyParts = New-Object System.Collections.Generic.List[byte[]]

        # ── Parte 1: scan_id ─────────────────────────────────────────────────
        $part1 = [System.Text.Encoding]::UTF8.GetBytes(
            "--$boundary`r`n" +
            "Content-Disposition: form-data; name=`"scan_id`"`r`n`r`n" +
            "$ScanId`r`n"
        )
        $bodyParts.Add($part1)

        # ── Parte 2: archivo PDF ─────────────────────────────────────────────
        $part2Header = [System.Text.Encoding]::UTF8.GetBytes(
            "--$boundary`r`n" +
            "Content-Disposition: form-data; name=`"file`"; filename=`"$filename`"`r`n" +
            "Content-Type: application/pdf`r`n`r`n"
        )
        $bodyParts.Add($part2Header)
        $bodyParts.Add($pdfBytes)

        $part2Footer = [System.Text.Encoding]::UTF8.GetBytes("`r`n")
        $bodyParts.Add($part2Footer)

        # ── Cierre ───────────────────────────────────────────────────────────
        $closing = [System.Text.Encoding]::UTF8.GetBytes("--$boundary--`r`n")
        $bodyParts.Add($closing)

        $totalSize = 0
        foreach ($part in $bodyParts) { $totalSize += $part.Length }

        $finalBody = [byte[]]::new($totalSize)
        $offset    = 0
        foreach ($part in $bodyParts) {
            [System.Array]::Copy($part, 0, $finalBody, $offset, $part.Length)
            $offset += $part.Length
        }

        Write-Host "Tamano del PDF: $($pdfBytes.Length) bytes"
        Write-Host "Tamano total del request: $totalSize bytes"

        $webRequest                            = [System.Net.WebRequest]::Create("$ServerUrl/scanner/receive")
        $webRequest.Method                     = "POST"
        $webRequest.ContentType                = "multipart/form-data; boundary=$boundary"
        $webRequest.ContentLength              = $finalBody.Length
        $webRequest.Headers["X-Scanner-Token"] = $Token
        $webRequest.Timeout                    = 60000

        $requestStream = $webRequest.GetRequestStream()
        $requestStream.Write($finalBody, 0, $finalBody.Length)
        $requestStream.Flush()
        $requestStream.Close()

        $webResponse    = $webRequest.GetResponse()
        $responseStream = $webResponse.GetResponseStream()
        $reader         = New-Object System.IO.StreamReader($responseStream)
        $responseBody   = $reader.ReadToEnd()
        $reader.Close()
        $webResponse.Close()

        Write-Host "Servidor respondio: $responseBody"

        $json = $responseBody | ConvertFrom-Json
        return $json.success

    } catch {
        Write-Host "Error al subir: $($_.Exception.Message)"

        if ($_.Exception.Response) {
            try {
                $errorStream = $_.Exception.Response.GetResponseStream()
                $errorReader = New-Object System.IO.StreamReader($errorStream)
                $errorBody   = $errorReader.ReadToEnd()
                Write-Host "Detalle del servidor: $errorBody"
                $errorReader.Close()
            } catch {}
        }

        return $false
    }
}

# ── Funciones de escritura PDF ────────────────────────────────────────────────
# FIX #3: Retornan [long] para evitar overflow en documentos grandes
function Write-Bytes {
    param([System.IO.FileStream]$Stream, [byte[]]$Data)
    $Stream.Write($Data, 0, $Data.Length)
    return [long]$Data.Length
}

function Write-Text {
    param([System.IO.FileStream]$Stream, [string]$Text)
    $bytes = [System.Text.Encoding]::GetEncoding(1252).GetBytes($Text)
    $Stream.Write($bytes, 0, $bytes.Length)
    return [long]$bytes.Length
}

# ── Escanea todas las hojas del ADF ──────────────────────────────────────────
function Scan-Document {
    param([string]$TempFolder)

    $deviceManager = New-Object -ComObject WIA.DeviceManager
    $device        = $null

    foreach ($info in $deviceManager.DeviceInfos) {
        if ($info.Type -eq 1) { $device = $info.Connect(); break }
    }

    if ($null -eq $device) { throw "No se encontro ningun escaner" }

    $item = $device.Items(1)
    if ($null -eq $item) { throw "No se pudo obtener el item del escaner" }

    try { $item.Properties("3088").Value = 4   } catch {}
    try { $item.Properties("6146").Value = 4   } catch {}
    try { $item.Properties("6147").Value = 300 } catch {}
    try { $item.Properties("6148").Value = 300 } catch {}

    $bmpFiles = [System.Collections.Generic.List[string]]::new()
    $pageNum  = 1

    # FIX #4: Limite de seguridad para evitar bucle infinito
    $maxPages = 100

    while ($pageNum -le $maxPages) {
        try {
            $image = $item.Transfer("{B96B3CAE-0728-11D3-9D7B-0000F81EF32E}")
            if ($null -eq $image) { break }

            $bmpFile = Join-Path $TempFolder ("page_{0:D4}.bmp" -f $pageNum)
            $image.SaveFile($bmpFile)

            if (Test-Path $bmpFile) {
                $bmpFiles.Add($bmpFile)
                Write-Host "  Hoja $pageNum escaneada"
                $pageNum++
            }
            Start-Sleep -Milliseconds 300
        } catch { break }
    }

    if ($pageNum -gt $maxPages) {
        Write-Host "ADVERTENCIA: Se alcanzo el limite de $maxPages paginas"
    }

    return $bmpFiles
}

# ── Genera el PDF desde los BMPs ──────────────────────────────────────────────
function Build-PDF {
    param(
        [System.Collections.Generic.List[string]]$BmpFiles,
        [string]$OutputFile
    )

    $jpegFiles = [System.Collections.Generic.List[string]]::new()
    $widths    = [System.Collections.Generic.List[int]]::new()
    $heights   = [System.Collections.Generic.List[int]]::new()
    $dpiXList  = [System.Collections.Generic.List[double]]::new()
    $dpiYList  = [System.Collections.Generic.List[double]]::new()

    $enc = [System.Drawing.Imaging.ImageCodecInfo]::GetImageEncoders() |
           Where-Object { $_.MimeType -eq 'image/jpeg' } |
           Select-Object -First 1

    $ep = New-Object System.Drawing.Imaging.EncoderParameters(1)
    $ep.Param[0] = New-Object System.Drawing.Imaging.EncoderParameter(
        [System.Drawing.Imaging.Encoder]::Quality, [long]90
    )

    for ($i = 0; $i -lt $BmpFiles.Count; $i++) {
        $img      = [System.Drawing.Image]::FromFile($BmpFiles[$i])
        $jpegFile = $BmpFiles[$i] -replace '\.bmp$', '.jpg'

        $w  = $img.Width
        $h  = $img.Height
        $dx = if ($img.HorizontalResolution -gt 0) { [double]$img.HorizontalResolution } else { [double]300 }
        $dy = if ($img.VerticalResolution   -gt 0) { [double]$img.VerticalResolution   } else { [double]300 }

        $widths.Add($w); $heights.Add($h)
        $dpiXList.Add($dx); $dpiYList.Add($dy)

        $img.Save($jpegFile, $enc, $ep)
        $img.Dispose()
        $jpegFiles.Add($jpegFile)
    }

    $numPages    = $jpegFiles.Count
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

    $fs = [System.IO.File]::Open($OutputFile, [System.IO.FileMode]::Create)

    # FIX #1: Header del PDF con salto de linea estandar
    $pos += Write-Text $fs "%PDF-1.4`r`n"

    for ($i = 0; $i -lt $numPages; $i++) {
        $jpegData = [System.IO.File]::ReadAllBytes($jpegFiles[$i])
        $wPx      = $widths[$i]
        $hPx      = $heights[$i]
        $wPt      = [math]::Round($wPx * 72.0 / $dpiXList[$i], 3)
        $hPt      = [math]::Round($hPx * 72.0 / $dpiYList[$i], 3)

        $imgN  = $imgObjs[$i]
        $contN = $contentObjs[$i]
        $pageN = $pageObjs[$i]

        $offsets[$imgN] = $pos
        $pos += Write-Text $fs "$imgN 0 obj`r`n"
        $pos += Write-Text $fs "<< /Type /XObject /Subtype /Image /Width $wPx /Height $hPx /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length $($jpegData.Length) >>`r`n"
        $pos += Write-Text $fs "stream`r`n"
        $pos += Write-Bytes $fs $jpegData
        $pos += Write-Text $fs "`r`nendstream`r`nendobj`r`n"

        $cs      = "q $wPt 0 0 $hPt 0 0 cm /Im$i Do Q"
        $csBytes = [System.Text.Encoding]::GetEncoding(1252).GetBytes($cs)

        $offsets[$contN] = $pos
        $pos += Write-Text $fs "$contN 0 obj`r`n<< /Length $($csBytes.Length) >>`r`nstream`r`n"
        $pos += Write-Bytes $fs $csBytes
        $pos += Write-Text $fs "`r`nendstream`r`nendobj`r`n"

        $offsets[$pageN] = $pos
        $pos += Write-Text $fs "$pageN 0 obj`r`n"
        $pos += Write-Text $fs "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $wPt $hPt] /Resources << /XObject << /Im$i $imgN 0 R >> >> /Contents $contN 0 R >>`r`n"
        $pos += Write-Text $fs "endobj`r`n"
    }

    $offsets[2] = $pos
    $kids = ($pageObjs | ForEach-Object { "$_ 0 R" }) -join " "
    $pos += Write-Text $fs "2 0 obj`r`n<< /Type /Pages /Kids [$kids] /Count $numPages >>`r`nendobj`r`n"

    $offsets[1] = $pos
    $pos += Write-Text $fs "1 0 obj`r`n<< /Type /Catalog /Pages 2 0 R >>`r`nendobj`r`n"

    # FIX #1: Entradas xref con exactamente 20 bytes usando `r`n
    $xrefPos = $pos
    $pos += Write-Text $fs "xref`r`n0 $($maxObjNum + 1)`r`n"
    $pos += Write-Text $fs "0000000000 65535 f `r`n"

    for ($n = 1; $n -le $maxObjNum; $n++) {
        $pos += Write-Text $fs ("{0:D10} 00000 n `r`n" -f [long]$offsets[$n])
    }

    $pos += Write-Text $fs "trailer`r`n<< /Size $($maxObjNum + 1) /Root 1 0 R >>`r`nstartxref`r`n$xrefPos`r`n%%EOF`r`n"

    $fs.Flush()
    $fs.Close()
    $fs.Dispose()

    foreach ($f in $jpegFiles) { if (Test-Path $f) { Remove-Item $f } }
}

# ── Ciclo principal ───────────────────────────────────────────────────────────
Write-Host "Agente iniciado"
Write-Host "Servidor: $ServerUrl"
Write-Host "Esperando solicitudes...`n"

$pingCounter = 0

while ($true) {
    try {
        $pingCounter++
        if ($pingCounter -ge 5) {
            Send-Ping
            $pingCounter = 0
        }

        $response = Get-Pending

        if ($null -eq $response -or -not $response.pending) {
            Start-Sleep -Seconds 2
            continue
        }

        $scanId = $response.scan_id

        # FIX #5: Proteccion contra scan_id corto o vacio
        $shortId = if ($scanId.Length -ge 8) { $scanId.Substring(0, 8) } else { $scanId }
        Write-Host "Solicitud recibida (ID: $shortId...)"

        Send-Status -ScanId $scanId -Status "scanning"

        $tempFolder = Join-Path $env:TEMP "scanner_$($scanId.Replace('-',''))"
        New-Item -ItemType Directory -Force -Path $tempFolder | Out-Null

        try {
            $bmpFiles = Scan-Document -TempFolder $tempFolder

            if ($bmpFiles.Count -eq 0) {
                Send-Status -ScanId $scanId -Status "error" -Message "No se escaneo ninguna hoja"
                continue
            }

            Write-Host "$($bmpFiles.Count) hojas. Generando PDF..."

            $pdfPath = Join-Path $tempFolder "documento.pdf"
            Build-PDF -BmpFiles $bmpFiles -OutputFile $pdfPath

            foreach ($bmp in $bmpFiles) { if (Test-Path $bmp) { Remove-Item $bmp } }

            Write-Host "Subiendo PDF..."
            $ok = Send-PDF -ScanId $scanId -PdfPath $pdfPath

            if ($ok) {
                # FIX #2: Notificar al servidor que el escaneo se completo
                Send-Status -ScanId $scanId -Status "completed"
                Write-Host "Completado`n"
            } else {
                Send-Status -ScanId $scanId -Status "error" -Message "No se pudo subir el PDF"
            }

        } finally {
            if (Test-Path $tempFolder) { Remove-Item $tempFolder -Recurse -Force }
        }

    } catch {
        Write-Host "Error: $_"
    }

    Start-Sleep -Seconds 2
}
