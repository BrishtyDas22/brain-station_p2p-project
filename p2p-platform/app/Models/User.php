<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The role assigned to a newly created user when nothing else specifies one.
     *
     * A registration NEVER grants the administrator role: the only way to
     * become an administrator is for an existing administrator to change the
     * role in the admin area, which is itself audited.
     */
    protected $attributes = [
        'role' => 'USER',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'role' => UserRole::class,
        ];
    }

    /**
     * The KYC submission for this user, if there is one.
     *
     * @return HasOne<KycSubmission, $this>
     */
    public function kycSubmission(): HasOne
    {
        return $this->hasOne(KycSubmission::class);
    }

    /**
     * The user's ledger account, if they have one.
     *
     * A user has at most one: `uq_ledger_accounts_owner` is UNIQUE on
     * `owner_user_id`. A user has NONE until their KYC is approved, at which
     * point `WalletProvisioningService` creates it.
     *
     * @return HasOne<LedgerAccount, $this>
     */
    public function ledgerAccount(): HasOne
    {
        return $this->hasOne(LedgerAccount::class, 'owner_user_id');
    }

    /**
     * The user's wallet, if they have one.
     *
     * Reached through the ledger account, because the wallet↔account mapping
     * is immutable and the account is the anchor.
     *
     * @return HasOne<Wallet, $this>
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class, 'owner_user_id');
    }

    /**
     * Whether this user holds the administrator role.
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    /**
     * The id of the user's ledger account, if they have one.
     *
     * A user has none until their KYC is approved. This is a convenience for the
     * money paths, which always need the account id and must fail loudly when it is
     * absent rather than writing a null into a foreign key.
     */
    public function ledgerAccountId(): int
    {
        $id = $this->ledgerAccount()->value('id');

        if ($id === null) {
            throw new \RuntimeException(
                'This user has no ledger account yet. Their identity must be approved first.'
            );
        }

        return (int) $id;
    }
}
