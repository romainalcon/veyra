<?php

namespace App\Http\Controllers\Organizations;

use App\Domain\Audit\Enums\AuditCategory;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only audit log of the organization, newest first (ADR-046). Owners
 * and Admins only; entries are never edited or deleted from here.
 */
class AuditLogController extends Controller
{
    public const PER_PAGE = 50;

    public function index(Request $request, Organization $organization): Response
    {
        Gate::authorize('viewAuditLog', $organization);

        $category = AuditCategory::tryFrom((string) $request->query('category'));

        $entries = AuditLog::query()
            ->where('organization_id', $organization->id)
            ->when($category !== null, fn ($query) => $query->where(function ($query) use ($category): void {
                foreach ($category->prefixes() as $prefix) {
                    $query->orWhere('action', 'like', $prefix.'%');
                }
            }))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (AuditLog $entry): array => [
                'id' => $entry->id,
                'action' => $entry->action->value,
                'label' => $entry->action->label(),
                'category' => $entry->action->category()->value,
                'actor' => [
                    'type' => $entry->actor_type->value,
                    'name' => $entry->actor_name,
                ],
                'subject' => $entry->subject_type === null ? null : [
                    'type' => $entry->subject_type,
                    'label' => $entry->subject_label,
                ],
                'changes' => $entry->changes,
                'metadata' => $entry->metadata,
                'created_at' => $entry->created_at->toIso8601String(),
            ]);

        return Inertia::render('organizations/AuditLog', [
            'entries' => $entries,
            'category' => $category?->value,
            'categories' => array_map(fn (AuditCategory $option): array => ['value' => $option->value, 'label' => $option->label()], AuditCategory::filters()),
            'retentionDays' => (int) config('veyra.audit.retention_days', 365),
        ]);
    }
}
