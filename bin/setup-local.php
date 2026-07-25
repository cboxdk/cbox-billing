<?php

declare(strict_types=1);

/**
 * Prepare `.env` for LOCAL development, then hand back to composer for migrate + seed.
 *
 * `.env.example` is deliberately production-shaped — Postgres, `APP_ENV=production`,
 * `APP_DEBUG=false`, an empty operator allowlist — and that is the right default for the file an
 * operator copies onto a server. It is the wrong default for the first thing a developer runs, and
 * the quickstart's own step 1 (`composer setup`) migrated against those Postgres credentials
 * BEFORE the docs told anyone to switch to SQLite. A clean clone failed with
 * `SQLSTATE[08006] … role "cbox_billing" does not exist`.
 *
 * This rewrites only the handful of keys that must differ locally, in place, preserving every
 * comment and every other value. It never touches an existing key it does not own, and it is
 * idempotent — running it twice is a no-op.
 *
 * It refuses to run against anything that does not provably look like a local `.env` unless
 * `--force` is passed, so nobody points it at a production environment file by accident.
 *
 * NOTE: `--force` must be passed by invoking this script directly —
 * `php bin/setup-local.php --force` — not through `composer setup:local`, which is an array script
 * and would append the flag to every line including `composer install`.
 */
$root = dirname(__DIR__);
$envPath = $root.'/.env';
$examplePath = $root.'/.env.example';
$force = in_array('--force', $argv, true);
$justCreated = false;

if (! file_exists($envPath)) {
    if (! file_exists($examplePath)) {
        fwrite(STDERR, "No .env and no .env.example to copy from.\n");
        exit(1);
    }

    copy($examplePath, $envPath);
    echo "Created .env from .env.example\n";
    $justCreated = true;
}

$env = file_get_contents($envPath);

if ($env === false) {
    fwrite(STDERR, "Could not read {$envPath}.\n");
    exit(1);
}

// Guard: proceed ONLY when this is provably a local env — an ALLOW-list, not a deny-list.
//
// The first version of this guard looked for credentials in the file and refused if it found
// them, which failed open on three realistic production shapes: secrets injected as container env
// vars (so `DB_PASSWORD=` is empty), a single `DB_URL=pgsql://user:secret@prod-pg/...` instead of
// discrete keys, and no `APP_ENV` line at all because it is set in the process environment. Any
// of those would have been rewritten and then handed to `migrate:fresh --seed --force`.
//
// So: the file must SAY `APP_ENV=local`, or be PRISTINE — no `APP_KEY` value yet. A copy of
// `.env.example` still says `APP_ENV=production` (correctly, since that file is production-shaped
// and is what an operator copies onto a server), so the environment line alone cannot distinguish
// a fresh clone from a real deployment. An unset `APP_KEY` can: the app has never been booted, so
// there is nothing there to destroy.
$declaredEnv = preg_match('/^APP_ENV[ \t]*=[ \t]*(.*)$/m', $env, $m) === 1
    ? trim(trim($m[1]), "\"'")
    : null;

$looksFresh = $justCreated || preg_match('/^APP_KEY[ \t]*=[ \t]*\S.*$/m', $env) !== 1;

if (! $force && $declaredEnv !== 'local' && ! $looksFresh) {
    $described = $declaredEnv === null ? 'no APP_ENV line' : "APP_ENV={$declaredEnv}";

    fwrite(STDERR,
        "Refusing to rewrite .env: it declares {$described}, so this does not look like a local\n".
        "development environment. This script rewrites database and debug settings and the caller\n".
        "then reseeds the database, which would be destructive against a real deployment.\n".
        "\n".
        "If this really is your local env, set APP_ENV=local in it first, or re-run with:\n".
        "  php bin/setup-local.php --force\n"
    );
    exit(1);
}

/**
 * The local-development overrides. Everything here differs from the production-shaped example
 * because a laptop is not a server — not because the example is wrong.
 *
 * @var array<string, string>
 */
$overrides = [
    'APP_ENV' => 'local',
    'APP_DEBUG' => 'true',
    'DB_CONNECTION' => 'sqlite',
    // The console is deny-by-default, so an empty allowlist denies every session — including the
    // demo sign-in the quickstart tells you to click. This is the org the demo user carries.
    'CBOX_BILLING_OPERATOR_ORGS' => '01demo0org0systems',
];

$changed = [];

foreach ($overrides as $key => $value) {
    $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

    if (preg_match($pattern, $env) === 1) {
        $updated = preg_replace($pattern, $key.'='.$value, $env, 1);

        if (is_string($updated) && $updated !== $env) {
            $env = $updated;
            $changed[] = $key;
        }

        continue;
    }

    $env = rtrim($env, "\n")."\n".$key.'='.$value."\n";
    $changed[] = $key;
}

// SQLite needs a file to exist before `migrate` will open it.
$database = $root.'/database/database.sqlite';

if (! file_exists($database)) {
    touch($database);
    echo "Created database/database.sqlite\n";
}

// The Postgres keys are meaningless on SQLite and only cause confusion when they are wrong.
$env = preg_replace('/^(DB_(HOST|PORT|DATABASE|USERNAME|PASSWORD)=.*)$/m', '# $1', $env) ?? $env;

if (file_put_contents($envPath, $env) === false) {
    // Silently continuing here would print success and exit 0, after which composer runs
    // `migrate:fresh --seed --force` against the UNMODIFIED, production-shaped .env.
    fwrite(STDERR, "Could not write {$envPath} — check its permissions (a container run may have left it root-owned).\n");
    exit(1);
}

echo $changed === []
    ? ".env already configured for local development\n"
    : 'Configured for local development: '.implode(', ', $changed)."\n";
