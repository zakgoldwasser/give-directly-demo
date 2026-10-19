param(
    [int] $Port = 8091,
    [string] $Image = 'give-directly-demo-vercel:acceptance'
)

$ErrorActionPreference = 'Stop'
$suffix = "{0}-{1}" -f $PID, ([DateTimeOffset]::UtcNow.ToUnixTimeSeconds())
$database = ("gd_acceptance_{0}" -f ($suffix -replace '-', '_')).ToLowerInvariant()
$container = "gd-acceptance-$suffix"
$volume = "gd-acceptance-storage-$suffix"
$directory = Join-Path $PSScriptRoot '..\.deployment-acceptance'
$envFile = Join-Path $directory "local-$suffix.env"
$checkpoint = Join-Path $directory "local-$suffix.json"
$pgsqlContainer = $null
$network = $null
$dbUser = $null
$defaultDatabase = $null
$failure = $null

function Assert-NativeSuccess([string] $Step) {
    if ($LASTEXITCODE -ne 0) {
        throw "$Step failed with exit code $LASTEXITCODE."
    }
}

function New-RandomBase64([int] $Bytes) {
    $buffer = [byte[]]::new($Bytes)
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()

    try {
        $generator.GetBytes($buffer)
    } finally {
        $generator.Dispose()
    }

    return [Convert]::ToBase64String($buffer)
}

try {
    New-Item -ItemType Directory -Path $directory -Force | Out-Null
    docker compose up -d pgsql | Out-Null
    Assert-NativeSuccess 'Starting PostgreSQL'
    docker build --file Dockerfile.vercel --tag $Image .
    Assert-NativeSuccess 'Building the production image'

    $pgsqlContainer = (docker compose ps -q pgsql).Trim()

    if (-not $pgsqlContainer) {
        throw 'The PostgreSQL container is not running.'
    }

    $network = (docker inspect --format '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}}{{end}}' $pgsqlContainer).Trim()
    $dbUser = (docker compose exec -T pgsql printenv POSTGRES_USER).Trim()
    $dbPassword = (docker compose exec -T pgsql printenv POSTGRES_PASSWORD).Trim()
    $defaultDatabase = (docker compose exec -T pgsql printenv POSTGRES_DB).Trim()

    docker compose exec -T pgsql createdb --username $dbUser $database
    Assert-NativeSuccess 'Creating the acceptance database'
    docker volume create $volume | Out-Null
    Assert-NativeSuccess 'Creating the acceptance storage volume'
    docker run --rm --user root --volume "${volume}:/app/storage/app/private" $Image sh -lc 'chown -R www-data:www-data /app/storage/app/private'
    Assert-NativeSuccess 'Preparing the acceptance storage volume'

    $appKey = "base64:{0}" -f (New-RandomBase64 32)
    $readinessSecret = New-RandomBase64 32
    $cronSecret = New-RandomBase64 32
    $environmentLines = @(
        'APP_NAME=Partner Donation Reconciliation'
        'APP_ENV=production'
        "APP_KEY=$appKey"
        'APP_DEBUG=false'
        "APP_URL=http://localhost:$Port"
        'LOG_CHANNEL=stderr'
        'DB_CONNECTION=pgsql'
        'DB_HOST=pgsql'
        'DB_PORT=5432'
        "DB_DATABASE=$database"
        "DB_USERNAME=$dbUser"
        "DB_PASSWORD=$dbPassword"
        'DB_SSLMODE=prefer'
        'SESSION_DRIVER=database'
        'SESSION_SECURE_COOKIE=false'
        'CACHE_STORE=database'
        'QUEUE_CONNECTION=sync'
        'RECONCILIATION_STORAGE_DRIVER=local'
        "RECONCILIATION_READINESS_SECRET=$readinessSecret"
        "CRON_SECRET=$cronSecret"
        'RECONCILIATION_ALLOW_ARBITRARY_UPLOADS=false'
    )
    [System.IO.File]::WriteAllLines(
        $envFile,
        $environmentLines,
        [System.Text.UTF8Encoding]::new($false)
    )

    docker run --rm --env-file $envFile --network $network --volume "${volume}:/app/storage/app/private" $Image php artisan migrate --force
    Assert-NativeSuccess 'Running migrations'
    docker run --rm --env-file $envFile --network $network --volume "${volume}:/app/storage/app/private" $Image php artisan reconciliation:verify-deployment --allow-local --json
    Assert-NativeSuccess 'Running deployment preflight'

    docker run --detach --name $container --env-file $envFile --network $network --volume "${volume}:/app/storage/app/private" --publish "${Port}:80" $Image | Out-Null
    Assert-NativeSuccess 'Starting the acceptance container'

    $available = $false

    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        try {
            $response = Invoke-WebRequest -Uri "http://localhost:$Port/up" -UseBasicParsing -TimeoutSec 2

            if ($response.StatusCode -eq 200) {
                $available = $true
                break
            }
        } catch {
            Start-Sleep -Milliseconds 500
        }
    }

    if (-not $available) {
        throw 'The production container did not become healthy.'
    }

    $env:DEPLOYMENT_URL = "http://localhost:$Port"
    $env:RECONCILIATION_READINESS_SECRET = $readinessSecret
    $env:DEPLOYMENT_CHECKPOINT = $checkpoint
    node scripts/deployment-acceptance.mjs prepare
    Assert-NativeSuccess 'Preparing the deployment checkpoint'

    docker rm --force $container | Out-Null
    Assert-NativeSuccess 'Replacing the acceptance container'
    docker run --detach --name $container --env-file $envFile --network $network --volume "${volume}:/app/storage/app/private" --publish "${Port}:80" $Image | Out-Null
    Assert-NativeSuccess 'Starting the replacement acceptance container'

    $available = $false

    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        try {
            $response = Invoke-WebRequest -Uri "http://localhost:$Port/up" -UseBasicParsing -TimeoutSec 2

            if ($response.StatusCode -eq 200) {
                $available = $true
                break
            }
        } catch {
            Start-Sleep -Milliseconds 500
        }
    }

    if (-not $available) {
        throw 'The replacement production container did not become healthy.'
    }

    node scripts/deployment-acceptance.mjs verify
    Assert-NativeSuccess 'Verifying persistence after replacement'
    Write-Host 'Local production-image deployment rehearsal passed.'
} catch {
    $failure = $_
} finally {
    $ErrorActionPreference = 'Continue'
    Remove-Item Env:DEPLOYMENT_URL -ErrorAction SilentlyContinue
    Remove-Item Env:RECONCILIATION_READINESS_SECRET -ErrorAction SilentlyContinue
    Remove-Item Env:DEPLOYMENT_CHECKPOINT -ErrorAction SilentlyContinue
    if (docker container inspect $container 2>$null) {
        docker rm --force $container 2>$null | Out-Null
    }

    if ($volume) {
        docker volume rm --force $volume 2>$null | Out-Null
    }

    if ($pgsqlContainer -and $dbUser -and $defaultDatabase) {
        docker compose exec -T pgsql dropdb --if-exists --force --username $dbUser $database 2>$null
    }

    Remove-Item -LiteralPath $envFile -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $checkpoint -Force -ErrorAction SilentlyContinue
}

if ($null -ne $failure) {
    $ErrorActionPreference = 'Stop'
    throw $failure
}
