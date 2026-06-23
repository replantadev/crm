<#
.SYNOPSIS
    Genera assets/data/municipios-es.json desde el diccionario oficial INE.

.DESCRIPTION
    Descarga el "Diccionario de municipios" oficial publicado por el INE
    (https://www.ine.es/daco/daco42/codmun/diccionario25.xlsx), lo parsea
    como XLSX y genera un JSON compacto:

        {
            "version":   "2025-01",
            "provincias": [
                { "code": "02", "name": "Albacete" },
                ...
            ],
            "municipios": {
                "02": ["Abengibre", "Alatoz", ...],
                "24": ["Acebedo", "Algadefe", ...],
                ...
            }
        }

    Para regenerar el bundle, ejecutar este script desde la raíz del plugin:

        powershell -ExecutionPolicy Bypass -File .\tools\build-municipios.ps1

    Después hacer commit del JSON actualizado y bumpear la versión del plugin.

.NOTES
    Fuente oficial: INE - Codificación territorial.
    Licencia: Reutilización libre con cita de la fuente.
#>

$ErrorActionPreference = 'Stop'

# Mapeo estable CPRO (código INE de provincia) -> nombre normalizado.
$provincias = @(
    @{ code = '01'; name = 'Álava'                  }
    @{ code = '02'; name = 'Albacete'               }
    @{ code = '03'; name = 'Alicante'               }
    @{ code = '04'; name = 'Almería'                }
    @{ code = '05'; name = 'Ávila'                  }
    @{ code = '06'; name = 'Badajoz'                }
    @{ code = '07'; name = 'Illes Balears'          }
    @{ code = '08'; name = 'Barcelona'              }
    @{ code = '09'; name = 'Burgos'                 }
    @{ code = '10'; name = 'Cáceres'                }
    @{ code = '11'; name = 'Cádiz'                  }
    @{ code = '12'; name = 'Castellón'              }
    @{ code = '13'; name = 'Ciudad Real'            }
    @{ code = '14'; name = 'Córdoba'                }
    @{ code = '15'; name = 'A Coruña'               }
    @{ code = '16'; name = 'Cuenca'                 }
    @{ code = '17'; name = 'Girona'                 }
    @{ code = '18'; name = 'Granada'                }
    @{ code = '19'; name = 'Guadalajara'            }
    @{ code = '20'; name = 'Gipuzkoa'               }
    @{ code = '21'; name = 'Huelva'                 }
    @{ code = '22'; name = 'Huesca'                 }
    @{ code = '23'; name = 'Jaén'                   }
    @{ code = '24'; name = 'León'                   }
    @{ code = '25'; name = 'Lleida'                 }
    @{ code = '26'; name = 'La Rioja'               }
    @{ code = '27'; name = 'Lugo'                   }
    @{ code = '28'; name = 'Madrid'                 }
    @{ code = '29'; name = 'Málaga'                 }
    @{ code = '30'; name = 'Murcia'                 }
    @{ code = '31'; name = 'Navarra'                }
    @{ code = '32'; name = 'Ourense'                }
    @{ code = '33'; name = 'Asturias'               }
    @{ code = '34'; name = 'Palencia'               }
    @{ code = '35'; name = 'Las Palmas'             }
    @{ code = '36'; name = 'Pontevedra'             }
    @{ code = '37'; name = 'Salamanca'              }
    @{ code = '38'; name = 'Santa Cruz de Tenerife' }
    @{ code = '39'; name = 'Cantabria'              }
    @{ code = '40'; name = 'Segovia'                }
    @{ code = '41'; name = 'Sevilla'                }
    @{ code = '42'; name = 'Soria'                  }
    @{ code = '43'; name = 'Tarragona'              }
    @{ code = '44'; name = 'Teruel'                 }
    @{ code = '45'; name = 'Toledo'                 }
    @{ code = '46'; name = 'Valencia'               }
    @{ code = '47'; name = 'Valladolid'             }
    @{ code = '48'; name = 'Bizkaia'                }
    @{ code = '49'; name = 'Zamora'                 }
    @{ code = '50'; name = 'Zaragoza'               }
    @{ code = '51'; name = 'Ceuta'                  }
    @{ code = '52'; name = 'Melilla'                }
)

$pluginDir = Split-Path -Parent $PSScriptRoot
$outDir    = Join-Path $pluginDir 'assets\data'
$outFile   = Join-Path $outDir 'municipios-es.json'
$xlsxPath  = Join-Path $PSScriptRoot 'ine-municipios.xlsx'

New-Item -ItemType Directory -Force -Path $outDir | Out-Null

# 1) Descargar XLSX oficial INE si no está cacheado.
if (-not (Test-Path $xlsxPath)) {
    Write-Host "Descargando diccionario INE..."
    Invoke-WebRequest `
        -Uri 'https://www.ine.es/daco/daco42/codmun/diccionario25.xlsx' `
        -OutFile $xlsxPath `
        -UseBasicParsing
}

# 2) Extraer sharedStrings.xml y sheet1.xml.
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead((Resolve-Path $xlsxPath))
try {
    $sstEntry = $zip.Entries | Where-Object { $_.FullName -eq 'xl/sharedStrings.xml' }
    $shEntry  = $zip.Entries | Where-Object { $_.FullName -eq 'xl/worksheets/sheet1.xml' }
    if (-not $sstEntry -or -not $shEntry) { throw 'XLSX no contiene las hojas esperadas.' }

    $sr     = New-Object System.IO.StreamReader($sstEntry.Open())
    $sstXml = $sr.ReadToEnd(); $sr.Close()

    $sr2   = New-Object System.IO.StreamReader($shEntry.Open())
    $shXml = $sr2.ReadToEnd(); $sr2.Close()
}
finally { $zip.Dispose() }

# 3) Parsear sharedStrings -> array de strings.
$sstDoc = New-Object System.Xml.XmlDocument
$sstDoc.LoadXml($sstXml)
$ns = New-Object System.Xml.XmlNamespaceManager($sstDoc.NameTable)
$ns.AddNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main')

$strings = New-Object System.Collections.Generic.List[string]
foreach ($si in $sstDoc.SelectNodes('//s:si', $ns)) {
    # Una <si> puede contener <t> directo o varias <r><t>...</t></r> (rich text).
    $tNodes = $si.SelectNodes('.//s:t', $ns)
    $sb = New-Object System.Text.StringBuilder
    foreach ($t in $tNodes) { [void]$sb.Append($t.InnerText) }
    $strings.Add($sb.ToString())
}
Write-Host ("Shared strings: {0}" -f $strings.Count)

# 4) Parsear filas. Saltamos cabecera (filas 1 y 2). Columnas: B=CPRO, E=NOMBRE.
$shDoc = New-Object System.Xml.XmlDocument
$shDoc.LoadXml($shXml)

$municipiosPorCpro = @{}
foreach ($p in $provincias) { $municipiosPorCpro[$p.code] = New-Object System.Collections.Generic.List[string] }

$rows = $shDoc.SelectNodes('//s:row', $ns)
$dataRows = 0
foreach ($row in $rows) {
    $rIdx = [int]$row.GetAttribute('r')
    if ($rIdx -lt 3) { continue }

    $cpro    = $null
    $nombre  = $null
    foreach ($c in $row.SelectNodes('s:c', $ns)) {
        $ref  = $c.GetAttribute('r')
        $col  = $ref -replace '[0-9]', ''
        $type = $c.GetAttribute('t')
        $vEl  = $c.SelectSingleNode('s:v', $ns)
        if (-not $vEl) { continue }
        $raw  = $vEl.InnerText
        $val  = if ($type -eq 's') { $strings[[int]$raw] } else { $raw }

        switch ($col) {
            'B' { $cpro   = $val }
            'E' { $nombre = $val }
        }
    }

    if (-not $cpro -or -not $nombre) { continue }
    $cproPadded = $cpro.PadLeft(2, '0')
    if (-not $municipiosPorCpro.ContainsKey($cproPadded)) { continue }
    $municipiosPorCpro[$cproPadded].Add($nombre.Trim())
    $dataRows++
}

Write-Host ("Filas procesadas: {0}" -f $dataRows)

# 5) Construir objeto final y volcar JSON ordenado.
$municipiosObj = [ordered]@{}
foreach ($p in ($provincias | Sort-Object code)) {
    $list = $municipiosPorCpro[$p.code]
    $sorted = $list | Sort-Object -Unique
    $municipiosObj[$p.code] = @($sorted)
    Write-Host ("  {0} {1}: {2}" -f $p.code, $p.name, $sorted.Count)
}

$payload = [ordered]@{
    version    = (Get-Date -Format 'yyyy-MM')
    source     = 'INE - Diccionario de municipios (ine.es/daco/daco42/codmun/diccionario25.xlsx)'
    provincias = @($provincias | Sort-Object code)
    municipios = $municipiosObj
}

# JSON compacto (sin indent) para minimizar tamaño en el cliente.
$json = $payload | ConvertTo-Json -Depth 6 -Compress
[System.IO.File]::WriteAllText($outFile, $json, [System.Text.UTF8Encoding]::new($false))

$fi = Get-Item $outFile
Write-Host ""
Write-Host ("OK -> {0}" -f $outFile)
Write-Host ("Tamaño: {0:N1} KB | {1} provincias | {2} municipios" -f ($fi.Length / 1KB), $provincias.Count, $dataRows)
