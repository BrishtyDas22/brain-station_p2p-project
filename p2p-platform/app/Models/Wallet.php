<?php

namespace App\Models;

use App\Casts\IntegerPoisha;
use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The money-holding table. This is where the only mutable balance in the system lives.
 *
 * Balances are integer minor units (Poisha; 1 BDT = 100 Poisha). `balance_minor` and
 * `reserved_minor` are `BIGINT UNSIGNED`, so a negative balance is physically
 * unrepresentable, and `ck_wallets_reserved_within_balance` keeps
 * `reserved_minor <= balance_minor` at all times.
 *
 * @property int $id
 * @property int $owner_user_id
 * @property int $account_id
 * @property string $currency
 * @property int $balance_minor
 * @property int $reserved_minor
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use HasFactory;

    /**
     * The auto-increment id is the only attribute that may be mass-assigned. Every
     * business column is guarded: balances are set explicitly, never by an unvalidated
     * array.
     *
     * @var list<string>
     */
    protected $fillable = ['id'];

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance_minor' => IntegerPoisha::class,
            'reserved_minor' => IntegerPoisha::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The ledger account backing this wallet. The binding is immutable.
     *
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }

    /**
     * The owning user.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Constrain the query to wallets owned by the given user.
     *
     * The read path scopes wallet queries to the owning user, so a balance is not
     * retrievable by a peer even by direct query.
     *
     * @param  Builder<Wallet>  $query
     * @return Builder<Wallet>
     */
    public function scopeForOwner(Builder $query, int $ownerUserId): Builder
    {
        return $query->where('owner_user_id', $ownerUserId);
    }

    /**
     * The funds currently available for a new transfer: posted balance less reserved.
     *
     * Guaranteed non-negative by `ck_wallets_reserved_within_balance`.
     */
    public function availableMinor(): int
    {
        return $this->balance_minor - $this->reserved_minor;
    }
}
