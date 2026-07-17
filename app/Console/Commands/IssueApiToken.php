<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Mint an API bearer token for a consuming platform — the deliberate alternative to
 * a shared static-token env. Operator tokens (no --org) may act for every org (a SaaS
 * platform billing its own tenants); an --org token is scoped to that one org. The
 * plaintext is shown ONCE — only its SHA-256 is stored.
 */
class IssueApiToken extends Command
{
    protected $signature = 'billing:token {name : A recognizable label, e.g. "cbox-assistant prod"} {--org= : Scope the token to one organization id (omit for an operator token)} {--product= : Bind the token to one product key (it only sees/sells that catalog)}';

    protected $description = 'Issue an API bearer token (operator-wide, or scoped to one organization)';

    public function handle(): int
    {
        $org = $this->option('org');
        $org = is_string($org) && $org !== '' ? $org : null;

        if ($org !== null && Organization::query()->find($org) === null) {
            $this->error("Unknown organization [{$org}] — create it first (PUT /api/v1/organizations/{org}).");

            return self::FAILURE;
        }

        $productKey = $this->option('product');
        $productId = null;

        if (is_string($productKey) && $productKey !== '') {
            $product = Product::query()->where('key', $productKey)->first();

            if ($product === null) {
                $this->error("Unknown product [{$productKey}] — seed its catalog first.");

                return self::FAILURE;
            }

            $productId = (int) $product->id;
        }

        ['plaintext' => $plaintext] = ApiToken::issue($this->argument('name'), $org, $productId);

        $scope = $org === null
            ? 'Operator token issued (acts for every organization)'
            : "Token issued, scoped to organization [{$org}]";

        if ($productId !== null) {
            $scope .= ", bound to product [{$productKey}]";
        }

        $this->info($scope.':');
        $this->newLine();
        $this->line('  '.$plaintext);
        $this->newLine();
        $this->warn('Store it now — only a hash is kept, it cannot be shown again.');

        return self::SUCCESS;
    }
}
