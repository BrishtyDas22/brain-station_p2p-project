<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SettlementBatch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The settlement overview.
 */
class SettlementController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $batches = SettlementBatch::query()->latest('opened_at')->get();

        return Inertia::render('admin/settlement', [
            'batches' => $batches->map(fn (SettlementBatch $b): array => [
                'id' => $b->id,
                'reference' => $b->reference,
                'network' => $b->network,
                'status' => $b->status,
                'entryCount' => $b->entry_count,
                'totalMinor' => $b->total_minor,
                'feeMinor' => $b->fee_minor,
                'netMinor' => $b->net_minor,
                'currency' => $b->currency,
                'openedAt' => $b->opened_at->format('d M Y, H:i'),
                'settledAt' => $b->settled_at?->format('d M Y, H:i'),
                'failureReason' => $b->failure_reason,
            ])->all(),
            'totals' => [
                'batches' => $batches->count(),
                'settled' => $batches->where('status', 'SETTLED')->count(),
                'pending' => $batches->where('status', 'PENDING')->count(),
                'processing' => $batches->where('status', 'PROCESSING')->count(),
                'failed' => $batches->where('status', 'FAILED')->count(),
                'netMinor' => (int) $batches->where('status', 'SETTLED')->sum('net_minor'),
                'entries' => (int) $batches->sum('entry_count'),
            ],
        ]);
    }
}
