<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Cbox\License\Support\Ed25519KeyPair;
use Illuminate\Console\Command;

/**
 * Generates a fresh Ed25519 issuer keypair for on-prem licensing and prints it. The
 * PRIVATE key is the issuer secret (set it as `CBOX_LICENSE_SIGNING_KEY`); the PUBLIC key
 * is safe to share and is bundled in the self-hosted deployment so it can verify licenses
 * offline (`CBOX_LICENSE_PUBLIC_KEY`).
 *
 * The keys are only printed — never written to disk, a config file, or git — so the
 * operator decides where the secret lives. Rotating the pair invalidates every license
 * signed with the old key.
 */
class LicenseKeygen extends Command
{
    protected $signature = 'billing:license-keygen';

    protected $description = 'Generate an Ed25519 issuer keypair for on-prem licensing (prints only; never writes keys to disk).';

    public function handle(): int
    {
        $pair = Ed25519KeyPair::generate();

        $this->newLine();
        $this->components->info('Generated a new Ed25519 licensing keypair.');

        $this->line('<comment>CBOX_LICENSE_SIGNING_KEY</comment> (PRIVATE — keep secret, never commit):');
        $this->line('  '.$pair['privateKey']);
        $this->newLine();

        $this->line('<comment>CBOX_LICENSE_PUBLIC_KEY</comment> (public — bundle in the self-hosted deployment):');
        $this->line('  '.$pair['publicKey']);
        $this->newLine();

        $this->components->warn(
            'Store the PRIVATE key as a secret (a secrets manager or the gitignored .env). '
            .'Anyone holding it can mint licenses. It is shown here once and never persisted by this command.',
        );

        return self::SUCCESS;
    }
}
