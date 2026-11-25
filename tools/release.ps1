<#
.SYNOPSIS
    Signs and publishes a release of this repository (kpskeletor, kpEdu, siFruit...) or a module /
    theme package, on the owner's Windows computer. Interactive: run `tools\release` and answer.

.DESCRIPTION
    It asks what to publish and suggests every value it can find:

    Application release
      - version: the newest "## [X.Y.Z]" section of CHANGELOG.md that has no tag yet;
      - checks: clean main equal to origin/main, new tag, in kpskeletor Version::CORE = X.Y.Z;
      - signed annotated tag vX.Y.Z (git tag -s: GPG or SSH, as git is configured), verified and
        pushed; <name>-X.Y.Z.zip from the tag (git archive), <zip>.sha256 and the minisign
        signature <zip>.minisig, both verified; GitHub release with the CHANGELOG section as notes.

    Module or theme package
      - chosen from the list of modules/ or themes/ (with their versions);
      - php spark modules:package <Name> --sign (the Core's builder, validated like
        modules:install; development environment); checksum and signature verified;
      - signed tag <Name>-v<version> (theme-<slug>-v<version>; not a semantic-version tag, so
        app:update never offers it) and a GitHub release that is not marked as the latest.

    "Test" mode runs every check and prints the commands without changing anything.
    Passwords are asked by git and minisign; nothing is stored. Files go to writable\releases.

    Requirements in PATH: git (tag signing configured), minisign, gh (gh auth login), and php for
    packages. docs/updates.md, "With the script".
#>

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
$script:DryRun = $false

function Fail([string] $Message) {
    Write-Host ''
    Write-Host "ERROR: $Message" -ForegroundColor Red
    exit 1
}

function Step([string] $Message) {
    Write-Host ''
    Write-Host "== $Message" -ForegroundColor Cyan
}

# Asks a question; Enter keeps the suggested value.
function Ask([string] $Question, [string] $Default = '') {
    $prompt = if ($Default -ne '') { "$Question [$Default]" } else { $Question }
    $answer = (Read-Host $prompt).Trim()
    if ($answer -eq '') {
        return $Default
    }
    return $answer
}

function Choose([string] $Question, [string[]] $Options) {
    Write-Host ''
    Write-Host $Question -ForegroundColor White
    for ($i = 0; $i -lt $Options.Count; $i++) {
        Write-Host ("  {0}) {1}" -f ($i + 1), $Options[$i])
    }
    while ($true) {
        $answer = Ask 'Number' '1'
        $n = 0
        if ([int]::TryParse($answer, [ref] $n) -and $n -ge 1 -and $n -le $Options.Count) {
            return $n - 1
        }
        Write-Host '  Write one of the numbers.' -ForegroundColor Yellow
    }
}

function Ask-YesNo([string] $Question) {
    return (Ask "$Question (y/n)" 'n') -match '^(y|yes|s|si)$'
}

# Runs a native command and stops on a non-zero exit code; in test mode it only prints it.
function Run([string] $Exe, [string[]] $Arguments, [switch] $Always) {
    $line = $Exe + ' ' + (($Arguments | ForEach-Object { if ($_ -match '\s') { '"' + $_ + '"' } else { $_ } }) -join ' ')
    if ($script:DryRun -and -not $Always) {
        Write-Host "  [test] $line" -ForegroundColor Yellow
        return
    }
    Write-Host "  > $line" -ForegroundColor DarkGray
    & $Exe @Arguments
    if ($LASTEXITCODE -ne 0) {
        Fail "'$Exe' ended with exit code $LASTEXITCODE."
    }
}

# Output of a read-only native command, or $null when it fails.
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

function Test-TagExists([string] $Tag) {
    if (Capture 'git' @('rev-parse', '-q', '--verify', "refs/tags/$Tag")) {
        return $true
    }
    return [bool] (Capture 'git' @('ls-remote', '--tags', 'origin', "refs/tags/$Tag"))
}

function Write-Checksum([string] $Zip) {
    $hash = (Get-FileHash -Algorithm SHA256 $Zip).Hash.ToLowerInvariant()
    [System.IO.File]::WriteAllText($Zip + '.sha256', $hash + '  ' + (Split-Path $Zip -Leaf) + "`n", (New-Object System.Text.ASCIIEncoding))
    Write-Host "  SHA-256: $hash"
}

function Assert-Checksum([string] $Zip) {
    $expected = ((Get-Content ($Zip + '.sha256') -Raw).Trim() -split '\s+')[0].ToLowerInvariant()
    if ($expected -ne (Get-FileHash -Algorithm SHA256 $Zip).Hash.ToLowerInvariant()) {
        Fail "The checksum of $Zip does not match its .sha256 file."
    }
    Write-Host '  Checksum OK'
}

function Push-SignedTag([string] $Tag, [string] $Message, [string] $Target) {
    Step "Signed tag $Tag (git may ask for the key's password)"
    Run 'git' @('tag', '-s', $Tag, '-m', $Message, $Target)
    Run 'git' @('tag', '-v', $Tag)
    Run 'git' @('push', 'origin', $Tag)
}

function Read-ManifestVersion([string] $File) {
    $text = Get-Content $File -Raw
    if ($text -match "public\s+string\s+\`$version\s*=\s*'([^']+)'") {
        return $Matches[1]
    }
    return '?'
}

# ---- Repository and tools -------------------------------------------------------------------
$root = Capture 'git' @('rev-parse', '--show-toplevel')
if (-not $root) {
    Fail 'Run it inside the repository: tools\release'
}
Set-Location $root
$composer = Get-Content (Join-Path $root 'composer.json') -Raw | ConvertFrom-Json
$name     = ($composer.name -split '/')[-1]
$origin   = Capture 'git' @('remote', 'get-url', 'origin')
if ($origin -notmatch 'github\.com[:/]([^/]+)/([^/]+?)(\.git)?$') {
    Fail "origin is not a GitHub repository: $origin"
}
$repo   = $Matches[1] + '/' + $Matches[2]
$outDir = Join-Path $root 'writable\releases'

Write-Host ''
Write-Host "Release of $repo ($name)" -ForegroundColor Green

Require 'git' 'Install Git for Windows.'
Require 'minisign' 'Download minisign.exe (https://github.com/jedisct1/minisign/releases) and add its folder to PATH.'
Require 'gh' 'Install the GitHub CLI (https://cli.github.com), then run: gh auth login'
if (-not (Capture 'git' @('config', 'user.signingkey'))) {
    Fail 'git cannot sign tags yet: git config user.signingkey <key> (for an SSH key also: git config gpg.format ssh). docs/updates.md, "With the script".'
}
if ($null -eq (Capture 'gh' @('auth', 'status'))) {
    Fail 'gh is not signed in: gh auth login'
}

# minisign keys: the usual place, else ask.
$secretKey = Join-Path $HOME '.minisign\kpskeletor.key'
if (-not (Test-Path $secretKey)) {
    $secretKey = Ask 'minisign secret key file'
    if (-not (Test-Path $secretKey)) {
        Fail "Not found: $secretKey (create one: minisign -G -p minisign.pub -s <file>)."
    }
}
$publicKey = ''
foreach ($candidate in @([System.IO.Path]::ChangeExtension($secretKey, '.pub'), (Join-Path (Split-Path $secretKey) 'minisign.pub'), (Join-Path $root 'minisign.pub'))) {
    if (Test-Path $candidate) {
        $publicKey = $candidate
        break
    }
}
if ($publicKey -eq '') {
    $publicKey = Ask 'minisign public key file (minisign.pub)'
    if (-not (Test-Path $publicKey)) {
        Fail "Not found: $publicKey"
    }
}
Write-Host "  minisign keys: $secretKey / $publicKey"

# ---- Clean main, equal to GitHub ------------------------------------------------------------
Step 'Checking the working tree'
Run 'git' @('fetch', '--tags', 'origin') -Always
$branch = Capture 'git' @('rev-parse', '--abbrev-ref', 'HEAD')
if ($branch -ne 'main') {
    Fail "Check out main first: git checkout main (current branch: $branch)."
}
$dirty = Capture 'git' @('status', '--porcelain', '--untracked-files=no')
if ($dirty) {
    Fail "There are uncommitted changes:`n$dirty"
}
$head = Capture 'git' @('rev-parse', 'HEAD')
if ($head -ne (Capture 'git' @('rev-parse', 'origin/main'))) {
    Fail 'main is not equal to GitHub: run git pull (and push your commits) first.'
}
Write-Host "  main = origin/main ($($head.Substring(0, 7)))"

# ---- What to publish ------------------------------------------------------------------------
$kind = Choose 'What do you want to publish?' @("A version of the application ($name)", 'A module package', 'A theme package')
$mode = Choose 'Mode' @('Test: run the checks and show the commands, change nothing', 'Publish')
$script:DryRun = ($mode -eq 0)
New-Item -ItemType Directory -Force -Path $outDir | Out-Null

if ($kind -eq 0) {
    # ---- Application release ----------------------------------------------------------------
    $changelog = Get-Content (Join-Path $root 'CHANGELOG.md')
    $versions = @()
    foreach ($line in $changelog) {
        if ($line -match '^## \[(\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?)\]') {
            $versions += $Matches[1]
        }
    }
    $suggested = ''
    foreach ($v in $versions) {
        if (-not (Test-TagExists "v$v")) {
            $suggested = $v
            break
        }
    }
    if ($suggested -eq '') {
        Fail "Every version of CHANGELOG.md has its tag already. Move the [Unreleased] changes to a new section '## [X.Y.Z] - <date>'$(if ($name -eq 'kpskeletor') { ', raise Version::CORE' }), merge it into main, and run this again."
    }
    $version = Ask 'Version' $suggested
    if ($versions -notcontains $version) {
        Fail "CHANGELOG.md has no section '## [$version]'."
    }
    $tag = "v$version"
    if (Test-TagExists $tag) {
        Fail "The tag $tag already exists (delete the release and the tag on GitHub first if it is a mistake)."
    }
    if ($name -eq 'kpskeletor') {
        $core = (Select-String -Path (Join-Path $root 'modules\Core\Config\Version.php') -Pattern "CORE\s*=\s*'([^']+)'").Matches[0].Groups[1].Value
        if ($core -ne $version) {
            Fail "Version::CORE is $core, not ${version}: raise it in modules\Core\Config\Version.php, merge it, and run this again."
        }
    }

    # The version's CHANGELOG section: the release notes.
    $start = [array]::IndexOf($changelog, ($changelog | Where-Object { $_ -match ('^## \[' + [regex]::Escape($version) + '\]') } | Select-Object -First 1))
    $end = $changelog.Count
    for ($i = $start + 1; $i -lt $changelog.Count; $i++) {
        if ($changelog[$i] -match '^## \[' -or $changelog[$i] -match '^\[[^\]]+\]: http') {
            $end = $i
            break
        }
    }
    $notes = Join-Path $outDir "$name-$version-notes.md"
    [System.IO.File]::WriteAllText($notes, (($changelog[$start..($end - 1)] -join "`n").Trim() + "`n"), (New-Object System.Text.UTF8Encoding($false)))
    $zip = Join-Path $outDir "$name-$version.zip"

    Write-Host ''
    Write-Host "Release $tag of $repo from commit $($head.Substring(0, 7)): $zip (+ .sha256, .minisig), notes $notes" -ForegroundColor White
    if (-not $script:DryRun -and -not (Ask-YesNo 'Publish it now?')) {
        Fail 'Cancelled.'
    }

    Push-SignedTag $tag "$name $version" $head

    Step "Archive $name-$version.zip"
    foreach ($f in @($zip, "$zip.sha256", "$zip.minisig")) {
        if ((Test-Path $f) -and -not $script:DryRun) {
            Remove-Item $f
        }
    }
    Run 'git' @('archive', '--format=zip', "--prefix=$name-$version/", '-o', $zip, $tag)
    if (-not $script:DryRun) {
        Write-Checksum $zip
    }

    Step 'minisign signature (minisign asks for the key password)'
    Run 'minisign' @('-S', '-s', $secretKey, '-m', $zip, '-t', "$name $version")
    if (-not $script:DryRun) {
        Assert-Checksum $zip
        Run 'minisign' @('-V', '-p', $publicKey, '-m', $zip)
    }

    Step "GitHub release $tag"
    Run 'gh' @('release', 'create', $tag, $zip, "$zip.sha256", "$zip.minisig", '--repo', $repo, '--title', "$name $version", '--notes-file', $notes, '--verify-tag', '--latest')
} else {
    # ---- Module or theme package ------------------------------------------------------------
    Require 'php' 'Install PHP (the same version as the project).'
    $isTheme = ($kind -eq 2)
    $items = @()
    if ($isTheme) {
        foreach ($dir in Get-ChildItem (Join-Path $root 'themes') -Directory) {
            $file = Join-Path $dir.FullName 'Theme.php'
            if (Test-Path $file) {
                $items += [pscustomobject] @{ Name = $dir.Name; Version = (Read-ManifestVersion $file) }
            }
        }
    } else {
        foreach ($dir in Get-ChildItem (Join-Path $root 'modules') -Directory) {
            $file = Join-Path $dir.FullName 'Config\Module.php'
            if ((Test-Path $file) -and $dir.Name -ne 'Core') {
                $items += [pscustomobject] @{ Name = $dir.Name; Version = (Read-ManifestVersion $file) }
            }
        }
    }
    if ($items.Count -eq 0) {
        Fail 'Nothing to package.'
    }
    $index   = Choose ($(if ($isTheme) { 'Which theme?' } else { 'Which module?' })) ($items | ForEach-Object { "$($_.Name) $($_.Version)" })
    $package = $items[$index].Name
    $version = $items[$index].Version
    $tag     = if ($isTheme) { "theme-$package-v$version" } else { "$package-v$version" }
    if (Test-TagExists $tag) {
        Fail "The tag $tag already exists: raise the version in the manifest (`$version), merge it, and run this again."
    }

    Write-Host ''
    Write-Host "Package $package $version of $repo, tag $tag, in $outDir" -ForegroundColor White
    Write-Host '  modules:package needs CI_ENVIRONMENT = development in .env.'
    if (-not $script:DryRun -and -not (Ask-YesNo 'Publish it now?')) {
        Fail 'Cancelled.'
    }

    Step "Building and signing the package (minisign key password asked by php spark)"
    $started = Get-Date
    $sparkArgs = @('spark', 'modules:package', $package, "--out=$outDir", "--sign=$secretKey")
    if ($isTheme) {
        $sparkArgs += '--theme'
    }
    Run 'php' $sparkArgs
    $zip = Join-Path $outDir "$package-$version.zip"
    if (-not $script:DryRun) {
        $found = Get-ChildItem $outDir -Filter "*-$version.zip" | Where-Object { $_.LastWriteTime -ge $started.AddSeconds(-2) } | Sort-Object LastWriteTime -Descending | Select-Object -First 1
        if (-not $found) {
            Fail "modules:package did not write the package in $outDir"
        }
        $zip = $found.FullName
        Assert-Checksum $zip
        Run 'minisign' @('-V', '-p', $publicKey, '-m', $zip)
    }

    Push-SignedTag $tag "$package $version" $head

    Step "GitHub release $tag"
    $label = if ($isTheme) { 'Theme' } else { 'Module' }
    $leaf  = Split-Path $zip -Leaf
    $text  = "$label $package $version. Install: php spark modules:install $leaf (with $leaf.minisig next to it). Check the download: sha256sum -c $leaf.sha256"
    Run 'gh' @('release', 'create', $tag, $zip, "$zip.sha256", "$zip.minisig", '--repo', $repo, '--title', "$package $version", '--notes', $text, '--verify-tag', '--latest=false')
}

Write-Host ''
if ($script:DryRun) {
    Write-Host 'Test finished: nothing was changed. Run it again and choose "Publish".' -ForegroundColor Green
} else {
    Write-Host "Done: https://github.com/$repo/releases/tag/$tag" -ForegroundColor Green
}
