<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssueApiTokenCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_issues_an_operator_token_that_authenticates(): void
    {
        $this->artisan('billing:token', ['name' => 'assistant prod'])
            ->expectsOutputToContain('Operator token issued')
            ->assertExitCode(0);

        $this->assertSame(1, ApiToken::query()->whereNull('organization_id')->count());
    }

    public function test_scoped_token_requires_an_existing_organization(): void
    {
        $this->artisan('billing:token', ['name' => 'x', '--org' => 'nope'])->assertExitCode(1);

        Organization::query()->create(['id' => 'org_1', 'name' => 'Acme']);
        $this->artisan('billing:token', ['name' => 'acme sdk', '--org' => 'org_1'])->assertExitCode(0);

        $this->assertSame(1, ApiToken::query()->where('organization_id', 'org_1')->count());
    }
}
