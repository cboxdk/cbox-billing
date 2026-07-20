<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Environments\EnvironmentRegistry;
use App\Billing\Environments\Promotion\Contracts\PromotesConfig;
use App\Billing\Environments\Promotion\Enums\ChangeStatus;
use App\Billing\Environments\Promotion\Exceptions\PromotionException;
use App\Billing\Environments\Promotion\PromotionGroup;
use App\Billing\Environments\Promotion\PromotionSelection;
use App\Billing\Environments\Promotion\ValueObjects\FieldChange;
use App\Billing\Environments\Promotion\ValueObjects\ObjectChange;
use App\Billing\Environments\Promotion\ValueObjects\PromotionPreview;
use Illuminate\Console\Command;

/**
 * Publish SELECTED config from one environment to another (the "promote the parts you want back
 * to production" flow). Thin adapter over the {@see PromotesConfig} contract: resolves the source
 * and target planes, builds a deny-by-default selection from `--only` groups and `--object`
 * type:key tokens, and either prints the diff (`--dry-run`) or applies it. The natural-key
 * matching, diff, relationship remapping and audit all live in the service.
 *
 *   php artisan environment:promote sandbox2 production --only=branding --dry-run
 *   php artisan environment:promote sandbox2 production --object=plan:pro --object=product:app
 */
class PromoteEnvironment extends Command
{
    protected $signature = 'environment:promote
        {source : The environment to publish config FROM}
        {target : The environment to publish config INTO (production is the go-live target)}
        {--only= : Comma-separated groups to promote (catalog,branding,mail,pricing-tables,coupons,dunning,experiments, or "all")}
        {--object=* : Individual objects as type:key (repeatable, or comma-separated)}
        {--dry-run : Print the diff preview without writing anything}';

    protected $description = 'Promote selected config from one environment to another, with a diff preview.';

    public function handle(EnvironmentRegistry $registry, PromotesConfig $promoter): int
    {
        $source = $registry->find($this->stringArg('source'));
        $target = $registry->find($this->stringArg('target'));

        if ($source === null || ! $source->exists) {
            $this->components->error(sprintf('Unknown source environment “%s”.', $this->stringArg('source')));

            return self::FAILURE;
        }

        if ($target === null || ! $target->exists) {
            $this->components->error(sprintf('Unknown target environment “%s”.', $this->stringArg('target')));

            return self::FAILURE;
        }

        $selection = PromotionSelection::fromInput($this->groups(), $this->objectTokens());

        if ($selection->isEmpty()) {
            $this->components->error('Nothing selected — pass --only=<groups> and/or --object=type:key (deny-by-default).');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $preview = $promoter->preview($source, $target, $selection);
            $this->renderPreview($preview);

            return $preview->hasConflicts() ? self::FAILURE : self::SUCCESS;
        }

        try {
            $result = $promoter->promote($source, $target, $selection);
        } catch (PromotionException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->renderPreview($result->preview);
        $this->components->info(sprintf(
            'Promoted “%s” → “%s”: %d created, %d updated, %d unchanged.',
            $result->source(),
            $result->target(),
            $result->created,
            $result->updated,
            $result->unchanged,
        ));

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function groups(): array
    {
        $only = $this->option('only');
        $only = is_string($only) ? trim($only) : '';

        if ($only === '') {
            return [];
        }

        if (strtolower($only) === 'all') {
            return array_map(static fn (PromotionGroup $g): string => $g->value, PromotionGroup::cases());
        }

        return array_values(array_filter(array_map('trim', explode(',', $only)), static fn (string $s): bool => $s !== ''));
    }

    /** @return list<string> */
    private function objectTokens(): array
    {
        $option = $this->option('object');
        $tokens = [];

        foreach ($option as $value) {
            if (! is_string($value)) {
                continue;
            }
            foreach (explode(',', $value) as $token) {
                $token = trim($token);
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        return $tokens;
    }

    private function stringArg(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    private function renderPreview(PromotionPreview $preview): void
    {
        $this->line(sprintf('<info>Promote</info> %s <info>→</info> %s', $preview->source, $preview->target));
        $this->newLine();

        if ($preview->changes === []) {
            $this->components->warn('No objects resolved from the selection.');
        }

        foreach ($preview->changes as $change) {
            $this->renderChange($change, 0);
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>Created</>', (string) $preview->createdCount());
        $this->components->twoColumnDetail('<fg=yellow>Updated</>', (string) $preview->updatedCount());
        $this->components->twoColumnDetail('<fg=gray>Unchanged</>', (string) $preview->unchangedCount());

        if ($preview->hasConflicts()) {
            $this->newLine();
            $this->components->error('Blocking conflicts — nothing will be promoted until they are resolved:');
            foreach ($preview->conflicts as $conflict) {
                $this->line('  <fg=red>✗</> '.$conflict->message());
            }
        }
    }

    private function renderChange(ObjectChange $change, int $depth): void
    {
        $indent = str_repeat('  ', $depth + 1);
        $tag = match ($change->status) {
            ChangeStatus::Created => '<fg=green>+ created</>',
            ChangeStatus::Updated => '<fg=yellow>~ updated</>',
            ChangeStatus::Unchanged => '<fg=gray>= unchanged</>',
        };

        $this->line(sprintf('%s%s  %s <fg=gray>(%s)</>', $indent, $tag, $change->token(), $change->label));

        foreach ($change->fieldChanges as $field) {
            $this->line($this->fieldLine($indent, $field));
        }

        foreach ($change->childChanges as $child) {
            $this->renderChange($child, $depth + 1);
        }
    }

    private function fieldLine(string $indent, FieldChange $field): string
    {
        return sprintf('%s    <fg=gray>%s:</> %s <fg=gray>→</> %s', $indent, $field->field, $field->old, $field->new);
    }
}
