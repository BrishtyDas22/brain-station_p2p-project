<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\IntegerPoisha;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One lender's commitment to one loan request.
 *
 * The row records that this lender committed this much capital to this listing. The
 * money itself is real: it left the lender's wallet and sits in the escrow ledger
 * account, backed by the balanced entry pair named by `transaction_id`.
 *
 * @property int $id
 * @property string $reference
 * @property int $loan_request_id
 * @property int $lender_user_id
 * @property int $amount_minor
 * @property int $transaction_id
 * @property string $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LoanInvestment extends Model
{
    /**
     * The auto-increment id is the only attribute that may be mass-assigned. Every
     * business column is guarded: money and identity are set explicitly, never by an
     * unvalidated array.
     *
     * @var list<string>
     */
    protected $fillable = ['id'];

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => IntegerPoisha::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The listing this commitment belongs to.
     *
     * @return BelongsTo<LoanRequest, $this>
     */
    public function loanRequest(): BelongsTo
    {
        return $this->belongsTo(LoanRequest::class, 'loan_request_id');
    }

    /**
     * The lender who committed the capital.
     *
     * @return BelongsTo<User, $this>
     */
    public function lender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lender_user_id');
    }

    /**
     * The ledger transaction the escrow-locking entry pair belongs to.
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    /**
     * Constrain the query to commitments made by the given lender.
     *
     * @param  Builder<LoanInvestment>  $query
     * @return Builder<LoanInvestment>
     */
    public function scopeForLender(Builder $query, int $lenderUserId): Builder
    {
        return $query->where('lender_user_id', $lenderUserId);
    }
}
