import { Head, Link, router } from '@inertiajs/react';
import { Activity, Filter, Search } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    DataTable,
    DirectionAmount,
    EmptyState,
    StatusBadge,
} from '@/p2p/components';
import type { LedgerDirection, RiskLevel, TransactionStatus } from '@/p2p/types';

type TransactionRow = {
    id: number;
    reference: string;
    counterpartyName: string;
    counterpartyAccount: string;
    amountMinor: number;
    feeMinor: number;
    currency: string;
    direction: LedgerDirection;
    status: TransactionStatus;
    statusLabel: string;
    riskLevel: RiskLevel;
    method: string;
    note: string;
    kind: 'ADD_MONEY' | 'TRANSFER';
    kindLabel: string;
    createdAt: string;
    chainClosedAt: string | null;
};

type TransactionsProps = {
    transactions: {
        data: TransactionRow[];
        links?: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
        current_page: number;
        last_page: number;
    };
    filters: { search: string; status: string; direction: string };
    statuses: string[];
};

export default function TransactionsIndex({
    transactions,
    filters,
    statuses,
}: TransactionsProps) {
    const [search, setSearch] = useState(filters.search);
    const [status, setStatus] = useState(filters.status);
    const [direction, setDirection] = useState(filters.direction);

    const apply = (overrides?: { status?: string; direction?: string }) => {
        router.get(
            '/transactions',
            {
                search,
                status: overrides?.status ?? status,
                direction: overrides?.direction ?? direction,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const rows = transactions.data ?? [];

    return (
        <>
            <Head title="Transactions" />

            <div className="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Filter className="size-4" />
                            Filters
                        </CardTitle>
                        <CardDescription>
                            Filtered on the server — the query runs against the database.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                apply();
                            }}
                            className="grid gap-3 sm:grid-cols-[1fr_auto_auto_auto]"
                        >
                            <div className="relative">
                                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    placeholder="Search by reference or counterparty…"
                                    className="pl-9"
                                    aria-label="Search transactions"
                                />
                            </div>

                            <Select
                                value={status}
                                onValueChange={(value) => {
                                    setStatus(value);
                                    apply({ status: value });
                                }}
                            >
                                <SelectTrigger className="w-[170px]" aria-label="Status">
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="ALL">All statuses</SelectItem>
                                    {statuses.map((option) => (
                                        <SelectItem key={option} value={option}>
                                            {option.replace(/_/g, ' ')}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <Select
                                value={direction}
                                onValueChange={(value) => {
                                    setDirection(value);
                                    apply({ direction: value });
                                }}
                            >
                                <SelectTrigger className="w-[150px]" aria-label="Direction">
                                    <SelectValue placeholder="Both" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="ALL">Both directions</SelectItem>
                                    <SelectItem value="DEBIT">Sent</SelectItem>
                                    <SelectItem value="CREDIT">Received</SelectItem>
                                </SelectContent>
                            </Select>

                            <Button type="submit">Apply</Button>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle>Transfer history</CardTitle>
                                <CardDescription>
                                    {transactions.total ?? rows.length} movement
                                    {transactions.total === 1 ? '' : 's'} recorded against
                                    your ledger account.
                                </CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="px-0">
                        {rows.length === 0 ? (
                            <EmptyState
                                icon={<Activity className="size-5" />}
                                title="No transfers match"
                                description="Clear the filters, or check back once a transfer has been posted to the ledger."
                            />
                        ) : (
                            <div className="px-6 pb-6">
                                <DataTable
                                    headers={[
                                        'Reference',
                                        'Type',
                                        'Counterparty',
                                        'Amount',
                                        'Fee',
                                        'Status',
                                        'Created',
                                    ]}
                                >
                                    {rows.map((row) => (
                                        <tr key={row.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3">
                                                <Link
                                                    href={`/transactions/${row.id}`}
                                                    className="font-mono text-xs font-medium hover:underline"
                                                >
                                                    {row.reference}
                                                </Link>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span
                                                    className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ${
                                                        row.kind === 'ADD_MONEY'
                                                            ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                                            : 'bg-muted text-muted-foreground'
                                                    }`}
                                                >
                                                    {row.kindLabel}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className="font-medium">
                                                    {row.counterpartyName || '—'}
                                                </span>
                                                {row.counterpartyAccount && (
                                                    <p className="font-mono text-xs text-muted-foreground">
                                                        {row.counterpartyAccount}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <DirectionAmount
                                                    amountMinor={row.amountMinor}
                                                    direction={row.direction}
                                                />
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground tabular-nums">
                                                {row.feeMinor === 0
                                                    ? '—'
                                                    : (row.feeMinor / 100).toFixed(2)}
                                            </td>
                                            <td className="px-4 py-3">
                                                <StatusBadge status={row.status} />
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                                {row.createdAt}
                                            </td>
                                        </tr>
                                    ))}
                                </DataTable>

                                {transactions.last_page > 1 && (
                                    <nav
                                        className="mt-4 flex items-center justify-between"
                                        aria-label="Pagination"
                                    >
                                        <p className="text-xs text-muted-foreground">
                                            Showing{' '}
                                            {transactions.from ?? 0}–{transactions.to ?? 0} of{' '}
                                            {transactions.total}
                                        </p>
                                        <div className="flex gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    transactions.current_page <= 1
                                                }
                                                onClick={() =>
                                                    router.get(
                                                        `/transactions?page=${
                                                            transactions.current_page - 1
                                                        }&search=${encodeURIComponent(
                                                            filters.search,
                                                        )}&status=${filters.status}&direction=${filters.direction}`,
                                                    )
                                                }
                                            >
                                                Previous
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    transactions.current_page >=
                                                    transactions.last_page
                                                }
                                                onClick={() =>
                                                    router.get(
                                                        `/transactions?page=${
                                                            transactions.current_page + 1
                                                        }&search=${encodeURIComponent(
                                                            filters.search,
                                                        )}&status=${filters.status}&direction=${filters.direction}`,
                                                    )
                                                }
                                            >
                                                Next
                                            </Button>
                                        </div>
                                    </nav>
                                )}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
