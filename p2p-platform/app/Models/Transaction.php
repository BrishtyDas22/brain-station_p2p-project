<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\IntegerPoisha;
use App\Enums\LedgerDirection;
use App\Enums\RiskLevel;
use App\Enums\TransactionStatus;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A transfer.
 *
 * `status` is a REBUILDABLE PROJECTION. The ledger entries are the authority;
 * where the two disagree, the ledger wins (epic-ledger-core R13).
 *
 * @property int $id
 * @property string $reference
 * @property int $sender_account_id
 * @property int|null $recipient_account_id
 * @property string $counterparty_name
 * @property string $counterparty_account
 * @property string $method
 * @property int $amount_minor
 * @property int $fee_minor
 * @property string $currency
 * @property LedgerDirection $direction
 * @property TransactionStatus $status
 * @property RiskLevel $risk_level
 * @property string|null $note
 * @property string $idempotency_key
 * @property Carbon|null $chain_closed_at
 * @property Carbon|null $reversed_at
 * @property int|null $reversal_of_transaction_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'reference',
        'sender_account_id',
        'recipient_account_id',
        'counterparty_name',
        'counterparty_account',
        'method',
        'amount_minor',
        'fee_minor',
        'currency',
        'direction',
        'status',
        'risk_level',
        'note',
        'idempotency_key',
        'chain_closed_at',
        'reversed_at',
        'reversal_of_transaction_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => LedgerDirection::class,
            'status' => TransactionStatus::class,
            'risk_level' => RiskLevel::class,
            'amount_minor' => IntegerPoisha::class,
            'fee_minor' => IntegerPoisha::class,
            'chain_closed_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function senderAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'sender_account_id');
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function recipientAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'recipient_account_id');
    }

    /**
     * The reversal raised against this transaction, if any.
     *
     * @return HasOne<Reversal, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(Reversal::class);
    }

    /**
     * The disputes raised against this transaction.
     *
     * @return HasMany<Dispute, $this>
     */
    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }
}
