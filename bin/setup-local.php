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
 * It refuses to run against a non-local `.env` unless `--force` is passed, so nobody points it at
 * a production environment file by accident.
 */
$root = dirname(__DIR__);
$envPath = $root.'/.env';
$examplePath = $root.'/.env.example';
$force = in_array('--force', $argv, true);

if (! file_exists($envPath)) {
    if (! file_exists($examplePath)) {
        fwrite(STDERR, "No .env and no .env.example to copy from.\n");
        exit(1);
    }

    copy($examplePath, $envPath);
    echo "Created .env from .env.example\n";
}

$env = file_get_contents($envPath);

if ($env === false) {
    fwrite(STDERR, "Could not read {$envPath}.\n");
    exit(1);
}

// Guard: never rewrite an env that looks like a real deployment.
if (! $force && preg_match('/^APP_ENV=(?!local)(\S+)/m', $env, $m) === 1 && $m[1] !== 'local') {
    $looksProvisioned = preg_match('/^DB_PASSWORD=.+/m', $env) === 1
        || preg_match('/^CBOX_ID_CLIENT_SECRET=.+/m', $env) === 1;

    if ($looksProvisioned) {
        fwrite(STDERR,
            "Refusing to rewrite .env: APP_ENV={$m[1]} and it carries credentials, so this looks\n".
            "like a real environment rather than a local one. Pass --force if you are sure.\n"
        );
        exit(1);
    }
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

file_put_contents($envPath, $env);

echo $changed === []
    ? ".env already configured for local development\n"
    : 'Configured for local development: '.implode(', ', $changed)."\n";
