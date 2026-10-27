<?php

namespace App\Enums;

/**
 * The declared type of a ledger account.
 *
 * The declared type must agree with whether the account has an owning user. That
 * agreement is enforced at the data layer by `ck_ledger_accounts_type`:
 *
 *  - `USER`   ⇒ `owner_user_id` IS NOT NULL and `system_role` IS NULL
 *  - `SYSTEM` ⇒ `owner_user_id` IS NULL     and `system_role` IS NOT NULL
 *
 * `SYSTEM` exists so the schema can accommodate H-1 (🟠 Proposed, not approved) without
 * a migration. Adopting H-1 is NOT authorised by this change: no SYSTEM account exists
 * and no funding path exists.
 */
enum LedgerAccountType: string
{
    case USER = 'USER';
    case SYSTEM = 'SYSTEM';
}
