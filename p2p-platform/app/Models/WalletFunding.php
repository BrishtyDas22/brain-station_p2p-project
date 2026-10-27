<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\IntegerPoisha;
use App\Enums\FundingMethod;
use App\Enums\FundingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A funding attempt — the record of an "Add Money" journey.
 *
 * The row records the attempt and its simulated gateway outcome. It does **not** hold the
 * money: a successful attempt posts a balanced pair of `ledger_entries` and advances the
 * main `wallets` projection, exactly as any other movement of money does.
 *
 * @property int $id
 * @property string $reference
 * @property int $owner_user_id
 * @property FundingMethod $method
 * @property FundingStatus $status
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $details
 * @property int|null $transaction_id
 * @property string $idempotency_key
 * @property Carbon|null $settled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WalletFunding extends Model
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
            'method' => FundingMethod::class,
            'status' => FundingStatus::class,
            'amount_minor' => IntegerPoisha::class,
            'details' => 'array',
            'settled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The paying user.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * The ledger transaction the balanced entry pair belongs to.
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    /**
     * Constrain the query to funding attempts owned by the given user.
     *
     * @param  Builder<WalletFunding>  $query
     * @return Builder<WalletFunding>
     */
    public function scopeForOwner(Builder $query, int $ownerUserId): Builder
    {
        return $query->where('owner_user_id', $ownerUserId);
    }
}
