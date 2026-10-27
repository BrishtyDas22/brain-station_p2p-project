<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The audit OUTBOX — intent carried atomically with money movement.
 *
 * This table carries SCALAR IDENTIFIERS ONLY and NO FOREIGN KEY to any
 * hot-path table. That is deliberate: an FK into `transactions`, `wallets`,
 * `ledger_accounts` or `ledger_entries` would make the outbox insert
 * participate in the money path's lock ordering, which is exactly how a
 * lock-order inversion — and therefore a deadlock — is introduced.
 *
 * The drain worker that consumes these rows is Epic 5 and is not built here.
 *
 * @property int $id
 * @property int|null $transaction_id
 * @property int|null $account_id
 * @property int|null $wallet_id
 * @property int|null $user_id
 * @property string $event_type
 * @property string $actor
 * @property array<string, mixed>|null $payload
 * @property Carbon $occurred_at
 * @property Carbon|null $drained_at
 * @property int $attempts
 */
class AuditOutbox extends Model
{
    protected $fillable = [
        'transaction_id',
        'account_id',
        'wallet_id',
        'user_id',
        'event_type',
        'actor',
        'payload',
        'occurred_at',
        'drained_at',
        'attempts',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'drained_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
