<?php

namespace App\Models;

use App\Casts\IntegerPoisha;
use App\Enums\LedgerAccountType;
use App\Enums\LedgerSystemRole;
use Database\Factories\LedgerAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * The ledger-side account. Exactly one wallet maps to one ledger account, and the
 * mapping is immutable.
 *
 * This model carries **no balance**. `next_sequence_number` is a per-account sequence
 * allocator, not a balance — it is reconciled by sequence continuity, not by balance
 * reconstruction.
 *
 * @property int $id
 * @property int|null $owner_user_id
 * @property LedgerAccountType $account_type
 * @property LedgerSystemRole|null $system_role
 * @property string $currency
 * @property int $next_sequence_number
 * @property Carbon|null $created_at
 */
class LedgerAccount extends Model
{
    /** @use HasFactory<LedgerAccountFactory> */
    use HasFactory;

    /**
     * `ledger_accounts` carries `created_at` only — the normative DDL has no
     * `updated_at`. A ledger account is not a mutable projection, so there is nothing
     * for an `updated_at` to record.
     */
    public $timestamps = false;

    /**
     * The auto-increment id is the only attribute that may be mass-assigned. Every
     * business column is guarded: money and identity are set explicitly, never by
     * an unvalidated array.
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
            'account_type' => LedgerAccountType::class,
            'system_role' => LedgerSystemRole::class,
            'next_sequence_number' => IntegerPoisha::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * The wallet backed by this account. At most one — `uq_wallets_account` guarantees it.
     *
     * The foreign key is named explicitly: the column is `account_id`, not the
     * `ledger_account_id` that Laravel's naming convention would infer.
     *
     * @return HasOne<Wallet, $this>
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class, 'account_id');
    }

    /**
     * The owning user. Null for a SYSTEM account.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * `ledger_accounts` has `created_at` but no `updated_at`, so the timestamp is set
     * explicitly rather than by Eloquent's timestamp machinery.
     */
    protected static function booted(): void
    {
        static::creating(function (self $account): void {
            $account->created_at ??= Carbon::now();
        });
    }

    /**
     * Whether this account is a SYSTEM account.
     */
    public function isSystem(): bool
    {
        return $this->account_type === LedgerAccountType::SYSTEM;
    }
}
