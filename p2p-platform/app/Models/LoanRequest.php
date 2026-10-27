<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\IntegerPoisha;
use App\Enums\LoanStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A loan request — one listing on the marketplace.
 *
 * The row records the listing and the funding progress. It does **not** hold a balance:
 * the capital a lender commits is real money that leaves the lender's wallet and sits in
 * the escrow ledger account until the loan is disbursed.
 *
 * `funded_minor` and `investor_count` are PROJECTIONS of the `loan_investments` rows.
 * They are advanced inside the same transaction that writes an investment, and the
 * investment rows remain the authority.
 *
 * @property int $id
 * @property string $reference
 * @property int $borrower_user_id
 * @property int $principal_minor
 * @property string $currency
 * @property int $interest_rate_bps
 * @property int $tenure_months
 * @property string $purpose
 * @property LoanStatus $status
 * @property int $funded_minor
 * @property int $investor_count
 * @property Carbon|null $funded_at
 * @property Carbon|null $repaid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LoanRequest extends Model
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
            'status' => LoanStatus::class,
            'principal_minor' => IntegerPoisha::class,
            'funded_minor' => IntegerPoisha::class,
            'interest_rate_bps' => 'integer',
            'tenure_months' => 'integer',
            'investor_count' => 'integer',
            'funded_at' => 'datetime',
            'repaid_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The borrower who listed this request.
     *
     * @return BelongsTo<User, $this>
     */
    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_user_id');
    }

    /**
     * The lenders' commitments to this request.
     *
     * @return HasMany<LoanInvestment, $this>
     */
    public function investments(): HasMany
    {
        return $this->hasMany(LoanInvestment::class, 'loan_request_id');
    }

    /**
     * The repayment schedule.
     *
     * @return HasMany<LoanInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class, 'loan_request_id');
    }

    /**
     * Constrain the query to requests owned by the given borrower.
     *
     * @param  Builder<LoanRequest>  $query
     * @return Builder<LoanRequest>
     */
    public function scopeForBorrower(Builder $query, int $borrowerUserId): Builder
    {
        return $query->where('borrower_user_id', $borrowerUserId);
    }

    /**
     * Constrain the query to listings that are still open for funding.
     *
     * @param  Builder<LoanRequest>  $query
     * @return Builder<LoanRequest>
     */
    public function scopeOpenForFunding(Builder $query): Builder
    {
        return $query->where('status', LoanStatus::MARKETPLACE_LISTED->value);
    }

    /**
     * How much capital is still needed to reach 100% funding.
     *
     * Never negative: the database CHECK forbids `funded_minor > principal_minor`, and
     * the funding path refuses an over-commitment before it gets that far.
     */
    public function remainingMinor(): int
    {
        return max(0, $this->principal_minor - $this->funded_minor);
    }

    /**
     * The funding progress as a whole-number percentage, 0–100.
     */
    public function fundingPercent(): int
    {
        if ($this->principal_minor <= 0) {
            return 0;
        }

        return (int) min(100, round(($this->funded_minor / $this->principal_minor) * 100));
    }

    /**
     * Whether this listing has reached 100% funding.
     */
    public function isFullyFunded(): bool
    {
        return $this->funded_minor >= $this->principal_minor;
    }
}
