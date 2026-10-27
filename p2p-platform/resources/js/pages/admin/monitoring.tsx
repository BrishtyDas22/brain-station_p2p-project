import { Head, Link, router } from '@inertiajs/react';
import { Activity, Search } from 'lucide-react';
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
import { DataTable, DirectionAmount, RiskBadge, StatusBadge } from '@/p2p/components';
import type { LedgerDirection, RiskLevel, TransactionStatus } from '@/p2p/types';

type MonitoringRow = {
    id: number;
    reference: string;
    accountHolder: string;
    counterpartyName: string;
    amountMinor: number;
    direction: LedgerDirection;
    status: TransactionStatus;
    statusLabel: string;
    riskLevel: RiskLevel;
    method: string;
    createdAt: string;
};

type MonitoringProps = {
    transactions: {
        data: MonitoringRow[];
        from: number | null;
        to: number | null;
        total: number;
        current_page: number;
        last_page: number;
    };
    filters: { search: string; status: string };
    statuses: string[];
};

export default function AdminMonitoring({
    transactions,
    filters,
    statuses,
}: MonitoringProps) {
    const [search, setSearch] = useState(filters.search);
    const [status, setStatus] = useState(filters.status);

    const apply = () => {
        router.get(
            '/admin/monitoring',
            { search, status },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const rows = transactions.data ?? [];

    return (
        <>
            <Head title="Transaction monitoring" />

            <div className="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Activity className="size-4" />
                            Platform transactions
                        </CardTitle>
                        <CardDescription>
                            Every transfer recorded on the platform, with the
                            counterparty and its current status.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                apply();
                            }}
                            className="grid gap-3 sm:grid-cols-[1fr_auto_auto]"
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
                                    router.get(
                                        '/admin/monitoring',
                                        { search, status: value },
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                            replace: true,
                                        },
                                    );
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

                            <Button type="submit">Apply</Button>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Transaction log</CardTitle>
                        <CardDescription>
                            {transactions.total} transfer
                            {transactions.total === 1 ? '' : 's'} across all accounts.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {rows.length === 0 ? (
                            <p className="py-10 text-center text-sm text-muted-foreground">
                                No transactions match the current filter.
                            </p>
                        ) : (
                            <DataTable
                                headers={[
                                    'Reference',
                                    'Account holder',
                                    'Counterparty',
                                    'Amount',
                                    'Status',
                                    'Risk',
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
                                        <td className="px-4 py-3">{row.accountHolder}</td>
                                        <td className="px-4 py-3">
                                            {row.counterpartyName || '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <DirectionAmount
                                                amountMinor={row.amountMinor}
                                                direction={row.direction}
                                            />
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={row.status} />
                                        </td>
                                        <td className="px-4 py-3">
                                            <RiskBadge level={row.riskLevel} />
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                            {row.createdAt}
                                        </td>
                                    </tr>
                                ))}
                            </DataTable>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
