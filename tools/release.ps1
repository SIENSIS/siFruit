<#
.SYNOPSIS
    Signs and publishes a release of this repository (kpskeletor, kpEdu, siFruit...) or a module /
    theme package, on the owner's Windows computer.

.DESCRIPTION
    Application release (default):
      1. checks: clean working tree on main, equal to origin/main; the tag does not exist; the
         CHANGELOG has a section "## [<version>]"; in kpskeletor, Version::CORE = <version>;
      2. signed annotated git tag v<version> (git tag -s: GPG or SSH, as git is configured),
         verified with git tag -v and pushed;
      3. <name>-<version>.zip from the tag (git archive, one top folder), <zip>.sha256 and the
         minisign signature <zip>.minisig (password asked by minisign), both verified;
      4. GitHub release v<version> with the three files and the CHANGELOG section as notes
         (GitHub CLI "gh").

    Module or theme package (-Module <Name> [-Theme]):
      1. checks: clean working tree on main, equal to origin/main; the tag does not exist;
      2. php spark modules:package <Name> --sign=<key> (the Core's builder: validated like
         modules:install, <zip>.sha256 and <zip>.minisig; development environment only);
      3. signed annotated git tag <Name>-v<version> (not a semantic version tag: app:update never
         offers it) and a GitHub release that is not marked as the latest.

    Nothing secret is stored: minisign and git ask for their passwords. -DryRun prints the
    commands without running them (the checks still run).

    Requirements in PATH: git (with tag signing configured: user.signingkey, gpg.format),
    minisign, gh (gh auth login), and php for -Module. docs/updates.md, "Release process".

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\release.ps1 -Version 1.2.0
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\release.ps1 -Module Glossary
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\release.ps1 -Module caparrella -Theme -DryRun
#>
[CmdletBinding()]
param(
    # Application version to release (e.g. 1.2.0). Required unless -Module.
    [string] $Version = '',
    # Module (or, with -Theme, theme slug) to package and publish instead of the application.
    [string] $Module = '',
    [switch] $Theme,
    # minisign secret key and public key.
    [string] $SecretKey = (Join-Path $HOME '.minisign\kpskeletor.key'),
    [string] $PublicKey = '',
    # Commit to release (application only). Default: origin/main.
    [string] $Commit = '',
    # Folder for the files (default: writable\releases).
    [string] $OutDir = '',
    # Release notes file instead of the CHANGELOG section (modules: default is a short line).
    [string] $NotesFile = '',
    [switch] $DryRun
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

function Fail([string] $Message) {
    Write-Host "ERROR: $Message" -ForegroundColor Red
    exit 1
}

function Step([string] $Message) {
    Write-Host ''
    Write-Host "== $Message" -ForegroundColor Cyan
}

# Runs a native command; stops on a non-zero exit code. With -DryRun it only prints it.
function Run([string] $Exe, [string[]] $Arguments, [switch] $Always) {
    $line = $Exe + ' ' + (($Arguments | ForEach-Object { if ($_ -match '\s') { '"' + $_ + '"' } else { $_ } }) -join ' ')
    if ($DryRun -and -not $Always) {
        Write-Host "  [dry-run] $line" -ForegroundColor Yellow
        return
    }
    Write-Host "  > $line" -ForegroundColor DarkGray
    & $Exe @Arguments
    if ($LASTEXITCODE -ne 0) {
        Fail "'$Exe' ended with exit code $LASTEXITCODE."
    }
}

# Output of a native command (always run: read-only).
function Capture([string] $Exe, [string[]] $Arguments) {
    # Windows PowerShell 5.1 turns a native command's stderr into an error under 'Stop'.
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $out = & $Exe @Arguments 2>$null
    $ErrorActionPreference = $previous
    if ($LASTEXITCODE -ne 0) {
        return $null
    }
    return (($out | Out-String).Trim())
}

function Require([string] $Exe, [string] $Hint) {
    if (-not (Get-Command $Exe -ErrorAction SilentlyContinue)) {
        Fail "'$Exe' is not in PATH. $Hint"
    }
}

# ---- Repository -----------------------------------------------------------------------------
$root = Capture 'git' @('rev-parse', '--show-toplevel')
if (-not $root) {
    Fail 'Run the script inside the repository (git rev-parse failed).'
}
Set-Location $root

$composer = Get-Content (Join-Path $root 'composer.json') -Raw | ConvertFrom-Json
$name     = ($composer.name -split '/')[-1]
$origin   = Capture 'git' @('remote', 'get-url', 'origin')
if ($origin -notmatch 'github\.com[:/]([^/]+)/([^/]+?)(\.git)?$') {
    Fail "origin is not a GitHub repository: $origin"
}
$repo = $Matches[1] + '/' + $Matches[2]

if ($OutDir -eq '') {
    $OutDir = Join-Path $root 'writable\releases'
}
if ($PublicKey -eq '') {
    # The .pub next to the secret key, minisign.pub there, or minisign.pub in the repository root.
    foreach ($candidate in @([System.IO.Path]::ChangeExtension($SecretKey, '.pub'), (Join-Path (Split-Path $SecretKey) 'minisign.pub'), (Join-Path $root 'minisign.pub'))) {
        if (Test-Path $candidate) {
            $PublicKey = $candidate
            break
        }
    }
}

Write-Host "Repository: $repo ($name)   Folder: $root"

Require 'git' 'Install Git for Windows.'
Require 'minisign' 'Download minisign.exe (https://github.com/jedisct1/minisign/releases) and add its folder to PATH.'
Require 'gh' 'Install the GitHub CLI (https://cli.github.com) and run: gh auth login'
if (-not (Test-Path $SecretKey)) {
    Fail "minisign secret key not found: $SecretKey (create it once: minisign -G -p minisign.pub -s $SecretKey)."
}
if ($PublicKey -eq '' -or -not (Test-Path $PublicKey)) {
    Fail 'minisign public key not found (use -PublicKey <path of minisign.pub>).'
}
if (-not (Capture 'git' @('config', 'user.signingkey'))) {
    Fail 'git has no signing key (git config user.signingkey ...; for SSH also git config gpg.format ssh).'
}
if ($null -eq (Capture 'gh' @('auth', 'status'))) {
    Fail 'gh is not signed in: gh auth login'
}

# ---- Common checks --------------------------------------------------------------------------
Step 'Checking the working tree'
Run 'git' @('fetch', '--tags', 'origin') -Always
$branch = Capture 'git' @('rev-parse', '--abbrev-ref', 'HEAD')
if ($branch -ne 'main') {
    Fail "Check out main first (current branch: $branch)."
}
$dirty = Capture 'git' @('status', '--porcelain', '--untracked-files=no')
if ($dirty) {
    Fail "The working tree has uncommitted changes:`n$dirty"
}
$local  = Capture 'git' @('rev-parse', 'HEAD')
$remote = Capture 'git' @('rev-parse', 'origin/main')
if ($local -ne $remote) {
    Fail 'main is not equal to origin/main: run git pull (and push what is yours) first.'
}

function Assert-NewTag([string] $Tag) {
    if (Capture 'git' @('rev-parse', '-q', '--verify', "refs/tags/$Tag")) {
        Fail "The tag $Tag already exists locally (git tag -d $Tag if it is a mistake)."
    }
    if (Capture 'git' @('ls-remote', '--tags', 'origin', "refs/tags/$Tag")) {
        Fail "The tag $Tag already exists on GitHub (delete the release and the tag first, or choose another version)."
    }
}

function Write-Checksum([string] $Zip) {
    $hash = (Get-FileHash -Algorithm SHA256 $Zip).Hash.ToLowerInvariant()
    $line = $hash + '  ' + (Split-Path $Zip -Leaf) + "`n"
    [System.IO.File]::WriteAllText($Zip + '.sha256', $line, (New-Object System.Text.ASCIIEncoding))
    Write-Host "  SHA-256: $hash"
}

function Assert-Checksum([string] $Zip) {
    $expected = ((Get-Content ($Zip + '.sha256') -Raw).Trim() -split '\s+')[0].ToLowerInvariant()
    $actual   = (Get-FileHash -Algorithm SHA256 $Zip).Hash.ToLowerInvariant()
    if ($expected -ne $actual) {
        Fail "The checksum of $Zip does not match its .sha256 file."
    }
    Write-Host '  Checksum OK'
}

function Push-SignedTag([string] $Tag, [string] $Message, [string] $Target) {
    Step "Signed tag $Tag"
    Run 'git' @('tag', '-s', $Tag, '-m', $Message, $Target)
    Run 'git' @('tag', '-v', $Tag)
    Run 'git' @('push', 'origin', $Tag)
}

New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

# ---- Module or theme package ----------------------------------------------------------------
if ($Module -ne '') {
    Require 'php' 'Install PHP (the same version as the project).'
    $manifest = if ($Theme) { Join-Path $root "themes\$Module\Theme.php" } else { Join-Path $root "modules\$Module\Config\Module.php" }
    if (-not (Test-Path $manifest)) {
        Fail "Not found: $manifest"
    }
    $text = Get-Content $manifest -Raw
    if ($text -notmatch "public\s+string\s+\`$version\s*=\s*'([^']+)'") {
        Fail "No version in $manifest"
    }
    $moduleVersion = $Matches[1]
    $tag = if ($Theme) { "theme-$Module-v$moduleVersion" } else { "$Module-v$moduleVersion" }
    Assert-NewTag $tag

    Step "Package of $Module $moduleVersion (php spark modules:package)"
    Write-Host '  It needs CI_ENVIRONMENT = development in .env; minisign asks for the key password.'
    $started = Get-Date
    $sparkArgs = @('spark', 'modules:package', $Module, "--out=$OutDir", "--sign=$SecretKey")
    if ($Theme) {
        $sparkArgs += '--theme'
    }
    Run 'php' $sparkArgs
    if ($DryRun) {
        $zip = Join-Path $OutDir "$Module-$moduleVersion.zip"
    } else {
        $found = Get-ChildItem $OutDir -Filter "*-$moduleVersion.zip" | Where-Object { $_.LastWriteTime -ge $started.AddSeconds(-2) } | Sort-Object LastWriteTime -Descending | Select-Object -First 1
        $zip = if ($found) { $found.FullName } else { '' }
        if ($zip -eq '') {
            Fail "modules:package did not write $Module-$moduleVersion.zip in $OutDir"
        }
        Assert-Checksum $zip
        Run 'minisign' @('-V', '-p', $PublicKey, '-m', $zip)
    }

    Push-SignedTag $tag "$Module $moduleVersion" 'HEAD'

    Step "GitHub release $tag"
    $notes = if ($NotesFile -ne '') { @('--notes-file', $NotesFile) } else { @('--notes', ("{0} {1} ({2}). Install: php spark modules:install {3} (with its .minisig next to it). Check: sha256sum -c {3}.sha256" -f ($(if ($Theme) { 'Theme' } else { 'Module' })), $moduleVersion, $Module, (Split-Path $zip -Leaf))) }
    Run 'gh' (@('release', 'create', $tag, $zip, "$zip.sha256", "$zip.minisig", '--repo', $repo, '--title', "$Module $moduleVersion", '--verify-tag', '--latest=false') + $notes)

    Write-Host ''
    Write-Host "Done: https://github.com/$repo/releases/tag/$tag" -ForegroundColor Green
    exit 0
}

# ---- Application release --------------------------------------------------------------------
if ($Version -notmatch '^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$') {
    Fail 'Give -Version X.Y.Z (or -Module <Name> for a module package).'
}
$tag = "v$Version"
Assert-NewTag $tag
if ($Commit -eq '') {
    $Commit = $remote
}

Step 'Checking the version'
$changelog = Get-Content (Join-Path $root 'CHANGELOG.md')
$start = -1
for ($i = 0; $i -lt $changelog.Count; $i++) {
    if ($changelog[$i] -match ('^## \[' + [regex]::Escape($Version) + '\]')) {
        $start = $i
        break
    }
}
if ($start -lt 0) {
    Fail "CHANGELOG.md has no section '## [$Version] - <date>': move the [Unreleased] changes there and merge it first."
}
$end = $changelog.Count
for ($i = $start + 1; $i -lt $changelog.Count; $i++) {
    if ($changelog[$i] -match '^## \[' -or $changelog[$i] -match '^\[[^\]]+\]: http') {
        $end = $i
        break
    }
}
$section = ($changelog[$start..($end - 1)] -join "`n").Trim()
Write-Host "  CHANGELOG: $($changelog[$start])"

$versionFile = Join-Path $root 'modules\Core\Config\Version.php'
if ($name -eq 'kpskeletor') {
    $core = (Select-String -Path $versionFile -Pattern "CORE\s*=\s*'([^']+)'").Matches[0].Groups[1].Value
    if ($core -ne $Version) {
        Fail "Version::CORE is $core, not $Version: raise it in modules\Core\Config\Version.php first."
    }
    Write-Host "  Version::CORE = $core"
}

Push-SignedTag $tag "$name $Version" $Commit

Step "Archive $name-$Version.zip"
$zip = Join-Path $OutDir "$name-$Version.zip"
foreach ($f in @($zip, "$zip.sha256", "$zip.minisig")) {
    if ((Test-Path $f) -and -not $DryRun) {
        Remove-Item $f
    }
}
Run 'git' @('archive', '--format=zip', "--prefix=$name-$Version/", '-o', $zip, $tag)
if (-not $DryRun) {
    Write-Checksum $zip
}

Step 'minisign signature (password asked by minisign)'
Run 'minisign' @('-S', '-s', $SecretKey, '-m', $zip, '-t', "$name $Version")
if (-not $DryRun) {
    Assert-Checksum $zip
    Run 'minisign' @('-V', '-p', $PublicKey, '-m', $zip)
}

Step "GitHub release $tag"
if ($NotesFile -eq '') {
    $NotesFile = Join-Path $OutDir "$name-$Version-notes.md"
    [System.IO.File]::WriteAllText($NotesFile, $section + "`n", (New-Object System.Text.UTF8Encoding($false)))
}
Run 'gh' @('release', 'create', $tag, $zip, "$zip.sha256", "$zip.minisig", '--repo', $repo, '--title', "$name $Version", '--notes-file', $NotesFile, '--verify-tag', '--latest')

Write-Host ''
Write-Host "Done: https://github.com/$repo/releases/tag/$tag" -ForegroundColor Green
Write-Host "Check from an installation: php spark updates:check; php spark app:update --dry-run"
