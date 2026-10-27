import { Head } from '@inertiajs/react';
import { BarChart3, Download, FileBarChart } from 'lucide-react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { DataTable, NoticeBanner, StatTile } from '@/p2p/components';
import { formatMoney } from '@/p2p/money';

type ReportsProps = {
    totals: { accounts: number; wallets: number; transactions: number };
    byStatus: {
        status: string;
        label: string;
        count: number;
        volumeMinor: number;
    }[];
    availability: { enabled: boolean; reason: string };
};

export default function Reports({ totals, byStatus, availability }: ReportsProps) {
    const maxCount = byStatus.reduce(
        (max, row) => Math.max(max, row.count),
        0,
    );

    return (
        <>
            <Head title="Reports" />

            <div className="space-y-6">
                <NoticeBanner tone="info">{availability.reason}</NoticeBanner>

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatTile
                        label="Ledger accounts"
                        value={totals.accounts}
                        hint="Live count"
                        icon={<FileBarChart className="size-4" />}
                    />
                    <StatTile
                        label="Wallets"
                        value={totals.wallets}
                        hint="Live count"
                        icon={<FileBarChart className="size-4" />}
                    />
                    <StatTile
                        label="Transactions"
                        value={totals.transactions}
                        hint="Live count"
                        icon={<BarChart3 className="size-4" />}
                        accent="primary"
                    />
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <CardTitle>Transactions by status</CardTitle>
                                <CardDescription>
                                    Grouped live from the transactions table.
                                </CardDescription>
                            </div>
                            <Button variant="outline" size="sm" disabled>
                                <Download className="size-3.5" />
                                Export — not yet available
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        {byStatus.length === 0 ? (
                            <p className="py-6 text-center text-sm text-muted-foreground">
                                No transactions recorded yet.
                            </p>
                        ) : (
                            <>
                                {/* Simple CSS bar chart — no charting dependency. */}
                                <div className="space-y-3">
                                    {byStatus.map((row) => (
                                        <div key={row.status} className="space-y-1.5">
                                            <div className="flex items-baseline justify-between gap-3 text-sm">
                                                <span className="font-medium">
                                                    {row.label}
                                                </span>
                                                <span className="text-muted-foreground tabular-nums">
                                                    {row.count} ·{' '}
                                                    {formatMoney(row.volumeMinor)}
                                                </span>
                                            </div>
                                            <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full bg-primary"
                                                    style={{
                                                        width: `${
                                                            maxCount > 0
                                                                ? Math.round(
                                                                      (row.count / maxCount) *
                                                                          100,
                                                                  )
                                                                : 0
                                                        }%`,
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                <DataTable
                                    headers={['Status', 'Count', 'Volume']}
                                >
                                    {byStatus.map((row) => (
                                        <tr key={row.status} className="hover:bg-muted/30">
                                            <td className="px-4 py-3">
                                                <Badge variant="outline">{row.label}</Badge>
                                            </td>
                                            <td className="px-4 py-3 tabular-nums">
                                                {row.count}
                                            </td>
                                            <td className="px-4 py-3 tabular-nums">
                                                {formatMoney(row.volumeMinor)}
                                            </td>
                                        </tr>
                                    ))}
                                </DataTable>
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
