<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Import\Adapters\AdapterRegistry;
use App\Billing\Import\Adapters\SourceExport;
use App\Billing\Import\Enums\ImportSource;
use App\Billing\Import\ImportRunner;
use App\Billing\Mode\BillingContext;
use App\Jobs\ImportCommitJob;
use App\Models\ImportRun;
use App\Models\Plan;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The Import area (Data → Import): pick a source, upload the provider export, review the DRY-RUN
 * report (per-entity counts + conflicts + the proposed plan mapping to adjust), then COMMIT the
 * import — inline for a small set, queued for a large one — with a browsable per-run log. Thin
 * over {@see ImportRunner} + {@see AdapterRegistry}; the parsing, resolution and writes live in
 * the pipeline. Every route is gated `settings:manage`; the run is scoped to the current plane.
 */
class ImportController extends Controller
{
    /** Above this many source records, the commit is queued rather than run inline. */
    private const QUEUE_THRESHOLD = 500;

    /** Per-file / combined-upload ceiling (KB) — a provider export is JSON text, not a large blob. */
    private const MAX_UPLOAD_KB = 10_240;

    /** The most upload files one preview accepts (a provider bundle is a handful of resources). */
    private const MAX_FILES = 25;

    /** Pasted-payload byte ceiling — the same 10 MB as an uploaded file. */
    private const MAX_PAYLOAD_BYTES = self::MAX_UPLOAD_KB * 1024;

    /** Max JSON nesting the importer will parse — deeper input is refused, never walked. */
    private const MAX_JSON_DEPTH = 64;

    public function __construct(
        private readonly AdapterRegistry $adapters,
        private readonly BillingContext $context,
    ) {}

    public function index(): View
    {
        return view('billing.import.index', [
            'activeArea' => 'data',
            'activeNav' => 'import',
            'livemode' => $this->context->livemode(),
            'sources' => array_map(static fn ($adapter): array => [
                'value' => $adapter->source()->value,
                'label' => $adapter->label(),
                'files' => $adapter->expectedFiles(),
            ], $this->adapters->all()),
            'runs' => ImportRun::query()->latest('id')->limit(25)->get(),
        ]);
    }

    /** Parse the upload, stage it, and render the dry-run plan — no writes. */
    public function preview(Request $request, ImportRunner $runner): View|RedirectResponse
    {
        // Bound the untrusted upload BEFORE it is read: constrain type (JSON/text export), file
        // count, and size on every ingest path so an oversized/wrong-type upload is refused up
        // front rather than slurped whole into memory and decoded inline.
        $request->validate([
            'source' => ['required', 'string'],
            'files' => ['sometimes', 'array', 'max:'.self::MAX_FILES],
            'files.*' => ['file', 'mimes:json,txt', 'max:'.self::MAX_UPLOAD_KB],
            'export' => ['sometimes', 'file', 'mimes:json,txt', 'max:'.self::MAX_UPLOAD_KB],
            'payload' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_PAYLOAD_BYTES],
        ]);

        $source = ImportSource::tryFromString($request->string('source')->toString());
        if ($source === null || ! $this->adapters->has($source)) {
            throw ValidationException::withMessages(['source' => 'Unsupported import source.']);
        }

        $export = $this->buildExport($request);
        if ($export === null) {
            return back()->withInput()->with('error', 'Upload the provider export file(s), or paste the combined JSON export.');
        }

        $adapter = $this->adapters->get($source);
        $dataset = $adapter->parse($export);
        if ($dataset->total() === 0) {
            return back()->withInput()->with('error', 'The export contained no recognisable records for '.$adapter->label().'.');
        }

        $user = $this->operator($request);
        $run = ImportRun::query()->create([
            'source' => $source->value,
            'status' => 'planned',
            'dry_run' => true,
            'actor_sub' => $user['sub'],
            'actor_name' => $user['name'],
        ]);

        $runner->stage($run, $export);

        // Gate the inline dry-run by the SAME threshold the commit queues at: above it, walking the
        // whole set inside the web request is unbounded work, so stage the run and hand the operator
        // to the queued commit path instead of rendering an inline preview.
        if ($dataset->total() > self::QUEUE_THRESHOLD) {
            return redirect()->route('billing.import.runs.show', $run->id)->with('status', sprintf(
                '%s staged with %d records — above the %d-record inline-preview limit, so the dry-run preview is skipped. Commit to process it as a queued job.',
                $adapter->label(),
                $dataset->total(),
                self::QUEUE_THRESHOLD,
            ));
        }

        $plan = $runner->plan($run);

        $run->forceFill([
            'counts' => $plan->counts(),
            'conflicts' => $plan->conflictsForStorage(),
        ])->save();

        return view('billing.import.plan', [
            'activeArea' => 'data',
            'activeNav' => 'import',
            'run' => $run,
            'plan' => $plan,
            'source' => $source,
            'sourcePlans' => $dataset->plans,
            'appPlans' => Plan::query()->orderBy('name')->get(['id', 'name', 'key']),
        ]);
    }

    /** Persist the (adjusted) mapping and commit — inline for a small set, queued for a large one. */
    public function commit(Request $request, ImportRun $importRun, ImportRunner $runner): RedirectResponse
    {
        if ($importRun->isCommitted()) {
            return redirect()->route('billing.import.runs.show', $importRun->id)->with('error', 'This run has already been committed.');
        }

        /** @var array<int|string, mixed> $mapping */
        $mapping = is_array($request->input('mapping')) ? $request->input('mapping') : [];
        $importRun->forceFill(['plan_mapping' => $mapping])->save();

        $total = $runner->parse($importRun)->total();

        if ($total > self::QUEUE_THRESHOLD) {
            ImportCommitJob::dispatch($importRun->id);

            return redirect()->route('billing.import.runs.show', $importRun->id)
                ->with('status', sprintf('Import queued (%d records) — this run\'s log will fill in as it processes.', $total));
        }

        $plan = $runner->commit($importRun);
        $counts = $plan->counts();
        $created = array_sum(array_map(static fn (array $o): int => $o['created'] ?? 0, $counts));

        return redirect()->route('billing.import.runs.show', $importRun->id)
            ->with('status', sprintf('Import committed — %d record(s) created, %d conflict(s).', $created, count($plan->conflicts())));
    }

    public function show(ImportRun $importRun): View
    {
        return view('billing.import.run', [
            'activeArea' => 'data',
            'activeNav' => 'import',
            'run' => $importRun,
            'entries' => $importRun->entries()->orderBy('id')->get()->groupBy('source_type'),
        ]);
    }

    /** Build the export from a multi-file upload, a single combined-JSON file, or a pasted payload. */
    private function buildExport(Request $request): ?SourceExport
    {
        $files = $request->file('files');
        if (is_array($files)) {
            $map = [];
            foreach ($files as $file) {
                if ($file->isValid()) {
                    $resource = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                    $map[$resource] = $this->boundedRead((string) file_get_contents((string) $file->getRealPath()));
                }
            }

            if ($map !== []) {
                return SourceExport::fromResources($map);
            }
        }

        $combined = $request->file('export');
        if ($combined instanceof UploadedFile && $combined->isValid()) {
            return SourceExport::fromCombinedJson($this->boundedRead((string) file_get_contents((string) $combined->getRealPath())));
        }

        $payload = trim($request->string('payload')->toString());
        if ($payload !== '') {
            return SourceExport::fromCombinedJson($this->boundedRead($payload));
        }

        return null;
    }

    /**
     * Return `$content` only if it is safe to parse: within the byte ceiling AND not nested past
     * {@see MAX_JSON_DEPTH}. Oversized or deeply-nested input is refused with a validation error
     * before any adapter walks it — a depth-limited decode never recurses into a hostile document.
     */
    private function boundedRead(string $content): string
    {
        if (strlen($content) > self::MAX_PAYLOAD_BYTES) {
            throw ValidationException::withMessages([
                'files' => 'The export is larger than the '.(self::MAX_UPLOAD_KB / 1024).' MB limit.',
            ]);
        }

        // A depth-capped decode fails closed on a document nested past the limit (JSON_ERROR_DEPTH).
        // Empty/whitespace content is left for the adapter to report as "no records".
        if (trim($content) !== '') {
            json_decode($content, true, self::MAX_JSON_DEPTH);

            if (json_last_error() === JSON_ERROR_DEPTH) {
                throw ValidationException::withMessages([
                    'files' => 'The export is nested too deeply to import safely.',
                ]);
            }
        }

        return $content;
    }

    /**
     * The signed-in operator (sub + name) from the console session, for the run's provenance.
     *
     * @return array{sub: ?string, name: ?string}
     */
    private function operator(Request $request): array
    {
        $user = $request->session()->get('auth.user');
        if (! is_array($user)) {
            return ['sub' => null, 'name' => null];
        }

        return [
            'sub' => isset($user['sub']) && is_string($user['sub']) ? $user['sub'] : null,
            'name' => isset($user['name']) && is_string($user['name']) ? $user['name'] : null,
        ];
    }
}
