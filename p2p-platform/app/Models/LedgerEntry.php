<?php

namespace App\Models;

use App\Casts\IntegerPoisha;
use App\Enums\LedgerDirection;
use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row of the append-only double-entry ledger.
 *
 * The ledger is the **authority**. `wallets.balance_minor` is a projection that can be
 * rebuilt from these entries; `ledger_accounts.next_sequence_number` is an allocator for
 * these entries. When a projection and the ledger disagree, the ledger wins (SPEC §4.3).
 *
 * Notes that a later reader must not undo:
 *
 *  - `amount_minor` and `balance_after` are `BIGINT UNSIGNED` integer Poisha
 *    (1 BDT = 100 Poisha), cast through `IntegerPoisha`, which **throws** on a value
 *    that is not an exact integer. Laravel's built-in `int` cast would silently
 *    truncate `'12.34'` to `12` — a silent loss of money on the money path.
 *  - `balance_after` is nullable and **not** part of the chained body (SPEC §5.2). It
 *    is not independently verifiable, because nobody can recompute it under
 *    concurrency. It is kept for display and debugging only. A null balance stays null
 *    — it never becomes zero.
 *  - There is **no `status` column**. H-6 / D1 is 🟠 Proposed (not approved), so the
 *    decision is not made here. A reversible migration that adds the column exists but
 *    is deliberately not applied.
 *  - Append-only is **not yet enforced** here. Story 3 installs the BEFORE UPDATE /
 *    BEFORE DELETE triggers; G4's privilege revocation is an infrastructure task. This
 *    model does not claim the guarantee holds.
 *
 * @property string $entry_id
 * @property int $transaction_id
 * @property int $account_id
 * @property LedgerDirection $direction
 * @property int $amount_minor
 * @property string $currency
 * @property int $account_sequence_number
 * @property int|null $balance_after
 * @property string $prev_hash
 * @property string $entry_hash
 * @property Carbon $created_at
 */
class LedgerEntry extends Model
{
    /** @use HasFactory<LedgerEntryFactory> */
    use HasFactory;

    /**
     * The primary key is a ULID in a `CHAR(26)` column, not an auto-increment.
     *
     * G1 / A-019 is ⚙️ Architecture default — PENDING. The reason for the choice is
     * recorded regardless: the id is inside the hashed body (SPEC §5.1), and an
     * auto-increment id is a *revealing* sequence — an observer can infer transfer
     * volume and timing from it.
     *
     * **Collation hazard.** MySQL's default `utf8mb4_0900_ai_ci` is case-INsensitive,
     * while a ULID's canonical Crockford Base32 encoding is case-sensitive. Two
     * distinct ULIDs differing only in case would collide on this primary key, and a
     * lookup could match the wrong row. The architecture spine does not fix a
     * collation, so this does not silently pick one. The conventional fix is
     * `ALTER TABLE ledger_entries CONVERT TO CHARACTER SET ascii COLLATE ascii_bin`.
     *
     * The hazard is currently latent: no ULID is generated yet and no entries exist.
     */
    protected $primaryKey = 'entry_id';

    /**
     * The primary key is a string, not an auto-increment integer.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * The entry id is the only attribute that may be mass-assigned. Every business
     * column is guarded: a ledger entry is written by a controlled path, never by
     * mass-assigning a request payload.
     *
     * @var list<string>
     */
    protected $fillable = ['id'];

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * The table has no `created_at`/`updated_at` pair. `created_at` is part of the
     * hashed body, so it is written once and never updated — an `updated_at` column
     * would be meaningless on an append-only table.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => LedgerDirection::class,
            'amount_minor' => IntegerPoisha::class,
            'balance_after' => IntegerPoisha::class,
            'transaction_id' => 'int',
            'account_id' => 'int',
            'account_sequence_number' => 'int',
        ];
    }

    /**
     * The ledger account this entry posts to.
     *
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }
}
