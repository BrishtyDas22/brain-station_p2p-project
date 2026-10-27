<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\IntegerPoisha;
use Database\Factories\ReversalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-shot reversal request and its decision.
 *
 * @property int $id
 * @property int $transaction_id
 * @property int $requested_by
 * @property int|null $decided_by
 * @property string $status
 * @property string $reason
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $compensating_entry_id
 * @property Carbon|null $decided_at
 * @property Carbon|null $executed_at
 */
class Reversal extends Model
{
    /** @use HasFactory<ReversalFactory> */
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'requested_by',
        'decided_by',
        'status',
        'reason',
        'amount_minor',
        'currency',
        'compensating_entry_id',
        'decided_at',
        'executed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => IntegerPoisha::class,
            'decided_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
