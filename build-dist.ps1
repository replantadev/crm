$ErrorActionPreference = 'Stop'

$pluginDir = "c:\Users\programacion2\Local Sites\crm\app\public\wp-content\plugins\crm-plugin"

# Detectar versión automáticamente desde el header del plugin para evitar
# tener que editar este script en cada release.
$versionLine = (Select-String -Path (Join-Path $pluginDir 'crm-plugin.php') -Pattern '^Version:\s*(.+)$' | Select-Object -First 1)
if (-not $versionLine) { throw "No se pudo leer la versión desde crm-plugin.php" }
$pluginVersion = ($versionLine.Matches[0].Groups[1].Value).Trim()
$gitTag        = "v$pluginVersion"
Write-Host "Empaquetando plugin versión $pluginVersion (tag $gitTag)"

$work      = Join-Path $env:TEMP ("crm-build-" + (Get-Date -Format yyyyMMddHHmmss))
$rawZip    = Join-Path $work "raw.zip"
$staging   = Join-Path $work "staging"
$outZip    = "C:\Users\programacion2\Desktop\crm-plugin-$pluginVersion.zip"

New-Item -ItemType Directory -Force -Path $work    | Out-Null
New-Item -ItemType Directory -Force -Path $staging | Out-Null

Push-Location $pluginDir
try {
    # 1) git archive directo a ZIP con prefijo crm-plugin/
    git archive --format=zip --prefix=crm-plugin/ --output="$rawZip" $gitTag
    if (-not (Test-Path $rawZip)) { throw "git archive fallo" }

    # 2) Expandir a staging para aplicar .distignore
    Expand-Archive -Path $rawZip -DestinationPath $staging -Force
    $exportDir = Join-Path $staging "crm-plugin"
    if (-not (Test-Path $exportDir)) { throw "Falta carpeta crm-plugin tras extraer" }

    # 3) Aplicar exclusiones del .distignore
    $ignoreExact = @(
        '.git','.gitignore','.gitattributes','.github','.vscode','.editorconfig','.distignore',
        'node_modules','vendor\bin','vendor\composer\installers',
        'composer.lock','phpunit.xml','phpunit.xml.dist','phpcs.xml','phpcs.xml.dist',
        'tests','docs','README.md',
        'DASHBOARD-COMPLETO.html','GUIA-IMPLEMENTACION.html','calendar.png',
        'crm-scriptv2.js','crm-plugin.php.disabled','crm.php.disabled','shortcodes.php.disabled',
        'build-dist.ps1'
    )
    foreach ($p in $ignoreExact) {
        $t = Join-Path $exportDir $p
        if (Test-Path $t) { Remove-Item -Recurse -Force $t }
    }
    Get-ChildItem -Path $exportDir -Recurse -Include *.md,*.log,*.disabled -Force -ErrorAction SilentlyContinue |
        Remove-Item -Force -ErrorAction SilentlyContinue

    # Excluir cualquier carpeta de backup del propio plugin que se hubiera colado.
    Get-ChildItem -Path $exportDir -Directory -Force -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -like 'crm-backup-*' -or $_.Name -like '*-backup-*' -or $_.Name -like 'backup-*' } |
        Remove-Item -Recurse -Force -ErrorAction SilentlyContinue

    # 4) Verificaciones criticas
    $pluginFile = Join-Path $exportDir 'crm-plugin.php'
    if (-not (Test-Path $pluginFile)) { throw "FALTA crm-plugin.php" }
    $ver = (Select-String -Path $pluginFile -Pattern '^Version:').Line.Trim()
    Write-Host "Header -> $ver"

    if (-not (Test-Path (Join-Path $exportDir 'vendor\autoload.php'))) {
        throw "FALTA vendor/autoload.php (necesario para PUC)"
    }
    foreach ($must in @('includes\security.php','includes\roles.php','includes\uploads-handler.php','includes\logger.php','includes\updater.php','includes\admin-page.php','shortcodes.php','acceso.php','uninstall.php')) {
        if (-not (Test-Path (Join-Path $exportDir $must))) { throw "FALTA $must" }
    }

    # 5) ZIP final entrada-a-entrada forzando separador '/' (WordPress en Linux
    #    no interpreta '\' como separador y rechaza el paquete). PowerShell 5.1
    #    Compress-Archive y ZipFile::CreateFromDirectory escriben '\' en Windows.
    if (Test-Path $outZip) { Remove-Item $outZip -Force }
    Add-Type -AssemblyName System.IO.Compression.FileSystem

    $rootName = Split-Path -Leaf $exportDir   # 'crm-plugin'
    $zipFs    = [System.IO.File]::Open($outZip, [System.IO.FileMode]::CreateNew)
    try {
        $archive = New-Object System.IO.Compression.ZipArchive($zipFs, [System.IO.Compression.ZipArchiveMode]::Create)
        try {
            Push-Location $exportDir
            try {
                $files = Get-ChildItem -Recurse -File -Force
                foreach ($f in $files) {
                    # Resolve-Path -Relative devuelve ".\sub\file.php" usando '\' como separador.
                    $rel = (Resolve-Path -LiteralPath $f.FullName -Relative).TrimStart('.').TrimStart('\','/')
                    $entryName = ($rootName + '/' + $rel) -replace '\\','/'
                    $entry = $archive.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)
                    $in  = [System.IO.File]::OpenRead($f.FullName)
                    try {
                        $out = $entry.Open()
                        try { $in.CopyTo($out) } finally { $out.Dispose() }
                    } finally { $in.Dispose() }
                }
            } finally { Pop-Location }
        } finally { $archive.Dispose() }
    } finally { $zipFs.Dispose() }

    $kb    = [math]::Round((Get-Item $outZip).Length / 1KB, 1)
    $count = (Get-ChildItem $exportDir -Recurse -File).Count
    Write-Host ""
    Write-Host "OK -> $outZip"
    Write-Host "    $kb KB | $count ficheros"
    Write-Host ""
    Write-Host "Raiz del paquete:"
    Get-ChildItem $exportDir | Sort-Object Name | ForEach-Object { "  " + $_.Name }
}
finally {
    Pop-Location
    if (Test-Path $work) { Remove-Item -Recurse -Force $work -ErrorAction SilentlyContinue }
}
