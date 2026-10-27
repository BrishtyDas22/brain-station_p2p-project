<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\IntegerPoisha;
use App\Enums\InstallmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One installment on a loan's repayment schedule.
 *
 * `principal_minor + interest_minor = amount_minor` is enforced by a raw-DDL CHECK
 * constraint, so an installment can never be internally inconsistent.
 *
 * @property int $id
 * @property int $loan_request_id
 * @property int $sequence
 * @property Carbon $due_date
 * @property int $amount_minor
 * @property int $principal_minor
 * @property int $interest_minor
 * @property InstallmentStatus $status
 * @property int|null $transaction_id
 * @property int $penalty_minor
 * @property Carbon|null $paid_at
 * @property Carbon|null $grace_started_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LoanInstallment extends Model
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
            'status' => InstallmentStatus::class,
            'sequence' => 'integer',
            'amount_minor' => IntegerPoisha::class,
            'principal_minor' => IntegerPoisha::class,
            'interest_minor' => IntegerPoisha::class,
            'penalty_minor' => IntegerPoisha::class,
            'due_date' => 'datetime',
            'paid_at' => 'datetime',
            'grace_started_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The loan this installment belongs to.
     *
     * @return BelongsTo<LoanRequest, $this>
     */
    public function loanRequest(): BelongsTo
    {
        return $this->belongsTo(LoanRequest::class, 'loan_request_id');
    }

    /**
     * The ledger transaction the collection entry pair belongs to.
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    /**
     * Constrain the query to installments in the given state.
     *
     * @param  Builder<LoanInstallment>  $query
     * @return Builder<LoanInstallment>
     */
    public function scopeWithStatus(Builder $query, InstallmentStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }
}
