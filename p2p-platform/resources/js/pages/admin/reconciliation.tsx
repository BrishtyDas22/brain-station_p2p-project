import { Head } from '@inertiajs/react';
import { CheckCircle2, RefreshCcw, XCircle } from 'lucide-react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { DataTable, NoticeBanner, StatTile } from '@/p2p/components';
import { cn } from '@/lib/utils';

type ReconciliationProps = {
    summary: {
        entries: number;
        transactions: number;
        accounts: number;
        wallets: number;
        violations: number;
        status: 'PASSED' | 'FAILED';
    };
    doubleEntryViolations: {
        transactionId: number;
        debits: number;
        credits: number;
        entryCount: number;
    }[];
    sequenceViolations: {
        accountId: number;
        expected: number;
        found: number;
    }[];
    mapping: { walletsWithoutAccount: number; accountsWithoutWallet: number };
    chainVerification: { available: boolean; reason: string };
};

export default function AdminReconciliation({
    summary,
    doubleEntryViolations,
    sequenceViolations,
    mapping,
    chainVerification,
}: ReconciliationProps) {
    const passed = summary.status === 'PASSED';

    return (
        <>
            <Head title="Reconciliation" />

            <div className="space-y-6">
                <NoticeBanner
                    tone={chainVerification.available ? 'success' : 'blocked'}
                >
                    {chainVerification.reason}
                </NoticeBanner>

                {/* Status banner */}
                <Card
                    className={
                        passed
                            ? 'border-emerald-500/30 bg-emerald-500/[0.05]'
                            : 'border-destructive/30 bg-destructive/[0.05]'
                    }
                >
                    <CardContent className="flex flex-wrap items-center gap-4">
                        {passed ? (
                            <CheckCircle2 className="size-8 text-emerald-600 dark:text-emerald-400" />
                        ) : (
                            <XCircle className="size-8 text-destructive" />
                        )}
                        <div>
                            <p className="font-semibold">
                                Reconciliation {summary.status}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {summary.violations === 0
                                    ? 'No invariant violations were found in the live tables.'
                                    : `${summary.violations} violation${
                                          summary.violations === 1 ? '' : 's'
                                      } found.`}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatTile
                        label="Ledger entries"
                        value={summary.entries}
                        hint={`${summary.transactions} transactions`}
                        icon={<RefreshCcw className="size-4" />}
                    />
                    <StatTile
                        label="Double-entry"
                        value={
                            doubleEntryViolations.length === 0
                                ? 'Balanced'
                                : `${doubleEntryViolations.length} unbalanced`
                        }
                        hint="SUM(DEBIT) = SUM(CREDIT) per transaction"
                        accent={
                            doubleEntryViolations.length === 0
                                ? 'success'
                                : 'danger'
                        }
                    />
                    <StatTile
                        label="Sequence continuity"
                        value={
                            sequenceViolations.length === 0
                                ? 'Continuous'
                                : `${sequenceViolations.length} gaps`
                        }
                        hint="Each account runs 1..n with no gap"
                        accent={
                            sequenceViolations.length === 0
                                ? 'success'
                                : 'danger'
                        }
                    />
                    <StatTile
                        label="Wallet mapping"
                        value={
                            mapping.walletsWithoutAccount +
                                mapping.accountsWithoutWallet ===
                            0
                                ? 'Intact'
                                : 'Orphans found'
                        }
                        hint="One wallet per account, immutable"
                        accent={
                            mapping.walletsWithoutAccount +
                                mapping.accountsWithoutWallet ===
                            0
                                ? 'success'
                                : 'danger'
                        }
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Double-entry violations</CardTitle>
                        <CardDescription>
                            Transactions whose debits do not equal their
                            credits.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {doubleEntryViolations.length === 0 ? (
                            <p className="py-6 text-center text-sm text-muted-foreground">
                                Every transaction balances.
                            </p>
                        ) : (
                            <DataTable
                                headers={[
                                    'Transaction',
                                    'Debits',
                                    'Credits',
                                    'Difference',
                                    'Entries',
                                ]}
                            >
                                {doubleEntryViolations.map((violation) => (
                                    <tr
                                        key={violation.transactionId}
                                        className="hover:bg-muted/30"
                                    >
                                        <td className="px-4 py-3 font-mono text-xs">
                                            #{violation.transactionId}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {(violation.debits / 100).toFixed(
                                                2,
                                            )}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {(violation.credits / 100).toFixed(
                                                2,
                                            )}
                                        </td>
                                        <td className="px-4 py-3 font-medium text-destructive tabular-nums">
                                            {(
                                                (violation.debits -
                                                    violation.credits) /
                                                100
                                            ).toFixed(2)}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {violation.entryCount}
                                        </td>
                                    </tr>
                                ))}
                            </DataTable>
                        )}
                    </CardContent>
                </Card>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Sequence continuity</CardTitle>
                            <CardDescription>
                                Accounts whose entries skip a position.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {sequenceViolations.length === 0 ? (
                                <p className="py-6 text-center text-sm text-muted-foreground">
                                    Every account's sequence is continuous.
                                </p>
                            ) : (
                                <ul className="space-y-2">
                                    {sequenceViolations.map((violation) => (
                                        <li
                                            key={violation.accountId}
                                            className="flex items-center justify-between rounded-lg border px-3 py-2 text-sm"
                                        >
                                            <span className="font-mono text-xs">
                                                Account #{violation.accountId}
                                            </span>
                                            <Badge
                                                variant="outline"
                                                className="border-destructive/30 bg-destructive/10 text-destructive dark:text-red-400"
                                            >
                                                expected {violation.expected},
                                                found {violation.found}
                                            </Badge>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Wallet / account mapping</CardTitle>
                            <CardDescription>
                                Orphans in either direction. A wallet must
                                belong to exactly one account, and the
                                relationship cannot be repointed or the account
                                deleted.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ul className="space-y-2">
                                <li
                                    className={cn(
                                        'flex items-center justify-between rounded-lg border px-3 py-2 text-sm',
                                        mapping.walletsWithoutAccount > 0 &&
                                            'border-destructive/30 bg-destructive/[0.04]',
                                    )}
                                >
                                    <span>Wallets without an account</span>
                                    <span className="font-semibold tabular-nums">
                                        {mapping.walletsWithoutAccount}
                                    </span>
                                </li>
                                <li
                                    className={cn(
                                        'flex items-center justify-between rounded-lg border px-3 py-2 text-sm',
                                        mapping.accountsWithoutWallet > 0 &&
                                            'border-destructive/30 bg-destructive/[0.04]',
                                    )}
                                >
                                    <span>Accounts without a wallet</span>
                                    <span className="font-semibold tabular-nums">
                                        {mapping.accountsWithoutWallet}
                                    </span>
                                </li>
                            </ul>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
