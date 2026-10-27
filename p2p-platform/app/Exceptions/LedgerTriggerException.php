<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a mandatory financial-integrity trigger cannot be installed.
 *
 * **Fail-closed by design.** A trigger that enforces a money invariant — the
 * wallet↔account binding being immutable, the ledger being append-only — is not
 * an optional convenience. If it is absent, the constraint it was supposed to
 * enforce is silently unenforced, and the database would accept writes that
 * corrupt the ledger. That is the exact failure mode this exception exists to
 * prevent.
 *
 * A migration that installs one of these triggers must abort rather than
 * continue. The alternative — logging a warning and carrying on — was the
 * previous behaviour, and it produced a schema that looked correct while
 * enforcing nothing.
 */
class LedgerTriggerException extends RuntimeException
{
    /**
     * @param  string  $triggerName  The trigger that could not be installed.
     * @param  string  $reason  The precise, actionable cause.
     */
    public static function forTrigger(string $triggerName, string $reason): self
    {
        return new self(
            "The mandatory trigger `{$triggerName}` could not be installed: {$reason} "
            .'This trigger enforces a financial-integrity invariant. The migration has been '
            .'aborted so the schema is never left in place without that invariant enforced.'
        );
    }

    /**
     * @param  string  $triggerName  The trigger that could not be verified.
     */
    public static function missingAfterInstall(string $triggerName): self
    {
        return new self(
            "The mandatory trigger `{$triggerName}` was reported as created but is not present "
            .'in the schema. The migration has been aborted so the schema is never left in place '
            .'without that invariant enforced.'
        );
    }
}
