<?php

namespace App\Http\Controllers\Monitors;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\Tag;
use App\Domain\Monitoring\Transfer\MonitorExport;
use App\Domain\Monitoring\Transfer\MonitorImport;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Monitor export and import (ADR-047). Exports contain no secrets and are
 * audited; imports are validated entry by entry with the interface rules,
 * previewed (dry run) and then created all at once.
 */
class MonitorTransferController extends Controller
{
    public const MAX_FILE_KB = 1024;

    public function export(Request $request, Organization $organization, AuditLogger $audit): StreamedResponse
    {
        Gate::authorize('viewAny', [Monitor::class, $organization]);

        $tag = is_string($request->query('tag')) && $request->query('tag') !== '' ? Tag::normalize($request->query('tag')) : null;
        $document = MonitorExport::document($organization, $tag);

        $audit->record($organization, AuditAction::MonitorsExported, metadata: array_filter([
            'count' => count($document['monitors']),
            'tag' => $tag,
        ], fn (mixed $value): bool => $value !== null));

        $filename = sprintf('veyra-monitors-%s-%s.json', $organization->slug, now()->format('Ymd-His'));

        return response()->streamDownload(
            fn () => print (json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            $filename,
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
        );
    }

    public function create(Organization $organization): Response
    {
        Gate::authorize('create', [Monitor::class, $organization]);

        return Inertia::render('monitors/Import', [
            'maxMonitors' => MonitorImport::maxEntries(),
            'maxFileKb' => self::MAX_FILE_KB,
        ]);
    }

    /**
     * Dry run: nothing is written; the result is flashed to the import page.
     */
    public function preview(Request $request, Organization $organization, MonitorImport $import): RedirectResponse
    {
        Gate::authorize('create', [Monitor::class, $organization]);

        [$document, $skipExisting] = $this->upload($request);

        Inertia::flash('importPreview', $import->preview($organization, $this->user($request), $document, $skipExisting)->toArray());

        return to_route('organizations.monitors.import.create', $organization);
    }

    public function store(Request $request, Organization $organization, MonitorImport $import): RedirectResponse
    {
        Gate::authorize('create', [Monitor::class, $organization]);

        [$document, $skipExisting] = $this->upload($request);

        $created = $import->import($organization, $this->user($request), $document, $skipExisting);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{0} No new monitor to import.|{1} One monitor imported.|[2,*] :count monitors imported.', $created)]);

        return to_route('organizations.monitors.index', $organization);
    }

    /**
     * @return array{0: array{format: string, version: int, monitors: list<array<array-key, mixed>>}, 1: bool}
     */
    private function upload(Request $request): array
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_FILE_KB],
            'skip_existing' => ['nullable', 'boolean'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        return [MonitorImport::decode((string) file_get_contents($file->getRealPath())), $request->boolean('skip_existing', true)];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
