<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Audit\AuditRecorder;
use App\Billing\Audit\Contracts\AssemblesDsarBundle;
use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Contracts\RedactsSubjectData;
use App\Billing\Audit\Contracts\ResolvesAuditActor;
use App\Billing\Audit\Dsar\DsarBundleBuilder;
use App\Billing\Audit\Redaction\SubjectErasureService;
use App\Billing\Audit\Support\AuditActorResolver;
use App\Billing\Audit\Support\AuditRequestTally;
use App\Billing\Tax\Exemptions\ExemptionCertificateService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the tamper-evident audit + GDPR/DSAR module. Everything is contract-bound so a host or
 * plugin can swap the recorder (e.g. to also mirror to a SIEM), the actor resolver, the DSAR
 * assembler, or the erasure policy without editing calling code.
 *
 * {@see AuditRequestTally} is a singleton shared by the recorder and the recording middleware so
 * the per-request "was anything recorded?" coordination works; {@see RecordsAudit} is a singleton
 * for the same reason.
 */
class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditRequestTally::class);

        $this->app->singleton(ResolvesAuditActor::class, AuditActorResolver::class);
        $this->app->singleton(RecordsAudit::class, AuditRecorder::class);

        $this->app->singleton(AssemblesDsarBundle::class, DsarBundleBuilder::class);

        // The erasure action deletes stored certificate documents from the private disk the
        // exemption module owns, so it is bound with that exact disk.
        $this->app->singleton(RedactsSubjectData::class, static fn (Container $app): SubjectErasureService => new SubjectErasureService(
            $app->make(ConnectionInterface::class),
            $app->make(RecordsAudit::class),
            $app->make(ResolvesAuditActor::class),
            Storage::disk(ExemptionCertificateService::DISK),
        ));
    }
}
