param (
    [string]$ServerUrl = "http://127.0.0.1:8000",
    [string]$Token = "P3uGVs0EIomc9Pn40yuGjMJL4KPUd9ZIaKolxak80OBJ24f30AgI4P5LBMCQxAjT",
    [string]$WatchFolder = [Environment]::GetFolderPath('MyDocuments'),
    [int]$WaitSeconds = 60,
    [string]$ArchiveFolder = "C:\ScannerAgente\processed"
)

$AgentVersion = "2.0.0-epson-folder"

[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$OutputEncoding = [System.Text.Encoding]::UTF8

if ([string]::IsNullOrWhiteSpace($ServerUrl)) {
    throw "ServerUrl no puede estar vacío"
}

if ([string]::IsNullOrWhiteSpace($Token)) {
    throw "Token no puede estar vacío"
}

if ([string]::IsNullOrWhiteSpace($WatchFolder)) {
    throw "WatchFolder no puede estar vacío"
}

if (-not (Test-Path $WatchFolder)) {
    New-Item -ItemType Directory -Force -Path $WatchFolder | Out-Null
}

if (-not (Test-Path $ArchiveFolder)) {
    New-Item -ItemType Directory -Force -Path $ArchiveFolder | Out-Null
}

$Headers = @{
    "X-Scanner-Token" = $Token
    "Accept"          = "application/json"
}

function Get-Pending {
    try {
        return Invoke-RestMethod `
            -Uri "$ServerUrl/scanner/pending" `
            -Method GET `
            -Headers $Headers `
            -TimeoutSec 5
    }
    catch {
        return $null
    }
}

function Send-Status {
    param([string]$ScanId, [string]$Status, [string]$Message = "")

    try {
        $body = @{
            scan_id = $ScanId
            status  = $Status
            message = $Message
        } | ConvertTo-Json

        Invoke-RestMethod `
            -Uri "$ServerUrl/scanner/update-status" `
            -Method POST `
            -Headers ($Headers + @{ "Content-Type" = "application/json" }) `
            -Body $body `
            -TimeoutSec 5 | Out-Null
    }
    catch {}
}

function Send-Ping {
    try {
        Invoke-RestMethod `
            -Uri "$ServerUrl/scanner/agent-ping" `
            -Method POST `
            -Headers $Headers `
            -TimeoutSec 5 | Out-Null
    }
    catch {}
}

function Wait-ForStablePdf {
    param(
        [string]$Folder,
        [datetime]$CreatedAfter,
        [int]$TimeoutSeconds = 60
    )

    $start = Get-Date

    while (((Get-Date) - $start).TotalSeconds -lt $TimeoutSeconds) {
        $candidate = Get-ChildItem -Path $Folder -Filter *.pdf -File -ErrorAction SilentlyContinue |
            Where-Object { $_.LastWriteTime -ge $CreatedAfter } |
            Sort-Object LastWriteTime -Descending |
            Select-Object -First 1

        if ($candidate) {
            $size1 = $candidate.Length
            Start-Sleep -Milliseconds 700

            $candidate = Get-Item $candidate.FullName -ErrorAction SilentlyContinue
            if ($candidate) {
                $size2 = $candidate.Length
                if ($size2 -gt 0 -and $size1 -eq $size2) {
                    return $candidate.FullName
                }
            }
        }

        Start-Sleep -Milliseconds 400
    }

    throw "No se encontró un PDF estable en la carpeta dentro del tiempo esperado."
}

function Send-PDF {
    param([string]$ScanId, [string]$PdfPath)

    try {
        $pdfBytes = [System.IO.File]::ReadAllBytes($PdfPath)

        if ($pdfBytes.Length -eq 0) {
            Write-Host "ERROR: El PDF está vacío"
            return $false
        }

        $filename = [System.IO.Path]::GetFileName($PdfPath)
        $boundary = "----FormBoundary$([System.Guid]::NewGuid().ToString('N'))"

        $bodyParts = New-Object System.Collections.Generic.List[byte[]]

        $bodyParts.Add([System.Text.Encoding]::UTF8.GetBytes(
            "--$boundary`r`n" +
            "Content-Disposition: form-data; name=`"scan_id`"`r`n`r`n" +
            "$ScanId`r`n"
        ))

        $bodyParts.Add([System.Text.Encoding]::UTF8.GetBytes(
            "--$boundary`r`n" +
            "Content-Disposition: form-data; name=`"file`"; filename=`"$filename`"`r`n" +
            "Content-Type: application/pdf`r`n`r`n"
        ))

        $bodyParts.Add($pdfBytes)
        $bodyParts.Add([System.Text.Encoding]::UTF8.GetBytes("`r`n"))
        $bodyParts.Add([System.Text.Encoding]::UTF8.GetBytes("--$boundary--`r`n"))

        $totalSize = 0
        foreach ($part in $bodyParts) { $totalSize += $part.Length }

        $finalBody = [byte[]]::new($totalSize)
        $offset = 0
        foreach ($part in $bodyParts) {
            [System.Array]::Copy($part, 0, $finalBody, $offset, $part.Length)
            $offset += $part.Length
        }

        $webRequest = [System.Net.WebRequest]::Create("$ServerUrl/scanner/receive")
        $webRequest.Method = "POST"
        $webRequest.ContentType = "multipart/form-data; boundary=$boundary"
        $webRequest.ContentLength = $finalBody.Length
        $webRequest.Headers["X-Scanner-Token"] = $Token
        $webRequest.Timeout = 60000

        $requestStream = $webRequest.GetRequestStream()
        $requestStream.Write($finalBody, 0, $finalBody.Length)
        $requestStream.Flush()
        $requestStream.Close()

        $webResponse = $webRequest.GetResponse()
        $responseStream = $webResponse.GetResponseStream()
        $reader = New-Object System.IO.StreamReader($responseStream)
        $responseBody = $reader.ReadToEnd()
        $reader.Close()
        $webResponse.Close()

        $json = $responseBody | ConvertFrom-Json
        return $json.success
    }
    catch {
        Write-Host "  Error al subir: $($_.Exception.Message)"
        if ($_.Exception.Response) {
            try {
                $errStream = $_.Exception.Response.GetResponseStream()
                $errReader = New-Object System.IO.StreamReader($errStream)
                Write-Host "  Detalle: $($errReader.ReadToEnd())"
                $errReader.Close()
            }
            catch {}
        }
        return $false
    }
}

function Archive-Pdf {
    param(
        [string]$PdfPath,
        [string]$ScanId
    )

    try {
        $baseName = [System.IO.Path]::GetFileNameWithoutExtension($PdfPath)
        $ext = [System.IO.Path]::GetExtension($PdfPath)
        $target = Join-Path $ArchiveFolder ("{0}_{1}{2}" -f $baseName, $ScanId, $ext)
        Move-Item -Path $PdfPath -Destination $target -Force
    }
    catch {
        Write-Host "  [WARN] No se pudo mover el PDF a procesados: $($_.Exception.Message)"
    }
}

Write-Host "Agente Epson v$AgentVersion iniciado"
Write-Host "Servidor:      $ServerUrl"
Write-Host "WatchFolder:   $WatchFolder"
Write-Host "ArchiveFolder: $ArchiveFolder"
Write-Host "Esperando solicitudes...`n"

$pingCounter = 0

while ($true) {
    try {
        $pingCounter++
        if ($pingCounter -ge 10) {
            Send-Ping
            $pingCounter = 0
        }

        $response = Get-Pending
        if ($null -eq $response -or -not $response.pending) {
            Start-Sleep -Milliseconds 500
            continue
        }

        $scanId = $response.scan_id
        $shortId = if ($scanId.Length -ge 8) { $scanId.Substring(0, 8) } else { $scanId }
        Write-Host "[$(Get-Date -Format 'HH:mm:ss')] Solicitud recibida (ID: $shortId...)"
        Write-Host "  Escanea ahora desde el botón del DS-770 II o ejecuta el Job en Document Capture Pro."

        $triggerTime = Get-Date

        try {
            $pdfPath = Wait-ForStablePdf -Folder $WatchFolder -CreatedAfter $triggerTime -TimeoutSeconds $WaitSeconds
            Write-Host "  PDF detectado: $pdfPath"

            $ok = Send-PDF -ScanId $scanId -PdfPath $pdfPath

            if ($ok) {
                Archive-Pdf -PdfPath $pdfPath -ScanId $scanId
                Write-Host "  Completado OK`n"
            }
            else {
                Send-Status -ScanId $scanId -Status "error" -Message "No se pudo subir el PDF generado por Epson"
            }
        }
        catch {
            Send-Status -ScanId $scanId -Status "error" -Message $_.Exception.Message
            Write-Host "  Error: $($_.Exception.Message)"
        }
    }
    catch {
        Write-Host "Error general: $_"
    }

    Start-Sleep -Milliseconds 300
}