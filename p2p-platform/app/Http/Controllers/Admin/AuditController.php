<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditRecord;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audit chain and the reconciliation summary.
 *
 * The audit chain is read-only. The reconciliation summary is computed from
 * the live tables: it reports what is actually there, including the fact that
 * the hash chain itself is not yet verifiable (M-2 is still BLOCKED).
 */
class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $records = AuditRecord::query()
            ->where('actor', '!=', '__sequence_counter__')
            ->latest('sequence')
            ->paginate(20);

        $entryCount = LedgerEntry::query()->count();
        $accountCount = LedgerAccount::query()->count();

        return Inertia::render('admin/audit', [
            'records' => $records->through(fn (AuditRecord $a): array => [
                'id' => $a->id,
                'sequence' => $a->sequence,
                'actor' => $a->actor,
                'action' => $a->action,
                'targetType' => $a->target_type,
                'targetId' => $a->target_id,
                'result' => $a->result,
                'at' => $a->occurred_at->format('d M Y, H:i'),
            ])->all(),
            'ledger' => [
                'entries' => $entryCount,
                'accounts' => $accountCount,
            ],
            'chainVerification' => [
                'available' => false,
                'reason' => 'M-2 — the canonicalization and cross-language hash contract is still open, so the chain verifier is not yet buildable. The append-only guarantee IS enforced by database triggers.',
            ],
        ]);
    }
}
