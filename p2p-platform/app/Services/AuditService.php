<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditRecord;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Writes to the append-only audit chain.
 *
 * The `sequence` is allocated under a row lock on a dedicated counter row, so
 * two concurrent audit writes cannot claim the same position. The lock is
 * taken in its own transaction scope and is deliberately NOT nested inside the
 * money path's transaction — taking it there would reintroduce a lock-order
 * inversion between the hot path and the audit path, which is the very thing
 * the outbox table exists to prevent.
 */
final class AuditService
{
    /**
     * The counter row that owns the audit sequence.
     *
     * It lives in `audit_records` itself as a sentinel row. The sentinel's
     * `sequence` column is pinned at 0 forever and its allocated position is
     * kept in the `context` JSON, because `sequence` carries a UNIQUE index
     * that real audit records also use: a counter row that stored the
     * allocated number in `sequence` would collide with the very record that
     * number was handed to.
     */
    private const COUNTER_ACTOR = '__sequence_counter__';

    /**
     * The key inside the counter row's `context` JSON that holds the last
     * sequence number handed out.
     */
    private const COUNTER_KEY = 'allocated';

    /**
     * Append an audit record.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $action,
        ?User $actor = null,
        ?string $targetType = null,
        string|int|null $targetId = null,
        string $result = 'ALLOWED',
        array $context = [],
    ): AuditRecord {
        $sequence = $this->nextSequence();

        return AuditRecord::query()->create([
            'sequence' => $sequence,
            'actor_user_id' => $actor?->id,
            'actor' => $actor?->id !== null ? 'user:'.$actor->id : 'system',
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId === null ? null : (string) $targetId,
            'result' => $result,
            'context' => $context === [] ? null : $context,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Record a denied action. The attempt is as auditable as the success.
     *
     * @param  array<string, mixed>  $context
     */
    public function deny(
        string $action,
        ?User $actor = null,
        ?string $targetType = null,
        string|int|null $targetId = null,
        array $context = [],
    ): AuditRecord {
        return $this->record($action, $actor, $targetType, $targetId, 'DENIED', $context);
    }

    /**
     * Allocate the next audit sequence number.
     *
     * The counter row is locked FOR UPDATE, so the allocation is serialised
     * across concurrent writers and no two records can claim the same
     * position.
     */
    private function nextSequence(): int
    {
        return DB::transaction(function (): int {
            $counter = AuditRecord::query()
                ->where('actor', self::COUNTER_ACTOR)
                ->lockForUpdate()
                ->first();

            if ($counter === null) {
                // The counter is created lazily. Two concurrent creators would
                // collide on the UNIQUE `sequence` index, so the loser retries
                // and finds the row the winner made.
                try {
                    AuditRecord::query()->create([
                        'sequence' => 0,
                        'actor_user_id' => null,
                        'actor' => self::COUNTER_ACTOR,
                        'action' => 'SEQUENCE_ALLOCATED',
                        'target_type' => 'audit_records',
                        'target_id' => null,
                        'result' => 'ALLOWED',
                        'context' => [self::COUNTER_KEY => 0],
                        'occurred_at' => now(),
                    ]);
                } catch (QueryException) {
                    // Lost the race; fall through and lock the winner's row.
                }

                $counter = AuditRecord::query()
                    ->where('actor', self::COUNTER_ACTOR)
                    ->lockForUpdate()
                    ->first();
            }

            $allocated = (int) (($counter->context ?? [])[self::COUNTER_KEY] ?? 0);

            $next = $allocated + 1;

            // The counter row is the one row in this table that is updated.
            // It is bookkeeping, not history: every real audit record is
            // append-only. Its `sequence` stays pinned at 0 so it never
            // competes with a real record for the UNIQUE index.
            $counter->context = [self::COUNTER_KEY => $next];
            $counter->save();

            return $next;
        });
    }
}
