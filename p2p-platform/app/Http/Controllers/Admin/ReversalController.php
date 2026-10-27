<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Reversal;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The administrator's reversal and dispute queue.
 */
class ReversalController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $reversals = Reversal::query()->with(['transaction', 'requester'])->latest()->get();
        $disputes = Dispute::query()->with(['transaction', 'opener'])->latest()->get();

        return Inertia::render('admin/reversals', [
            'reversals' => $reversals->map(fn (Reversal $r): array => [
                'id' => $r->id,
                'transactionReference' => $r->transaction->reference,
                'requestedBy' => $r->requester->name,
                'amountMinor' => $r->amount_minor,
                'currency' => $r->currency,
                'status' => $r->status,
                'reason' => $r->reason,
                'createdAt' => $r->created_at?->format('d M Y, H:i') ?? '',
                'decidedAt' => $r->decided_at?->format('d M Y, H:i'),
                'compensatingEntryId' => $r->compensating_entry_id,
            ])->all(),
            'disputes' => $disputes->map(fn (Dispute $d): array => [
                'id' => $d->id,
                'transactionReference' => $d->transaction->reference,
                'openedBy' => $d->opener->name,
                'subject' => $d->subject,
                'category' => $d->category,
                'status' => $d->status,
                'priority' => $d->priority,
                'description' => $d->description,
                'openedAt' => $d->created_at?->format('d M Y, H:i') ?? '',
                'resolvedAt' => $d->resolved_at?->format('d M Y, H:i'),
            ])->all(),
        ]);
    }
}
