import { Head } from '@inertiajs/react';
import { FileSearch, Lock } from 'lucide-react';
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

type AuditRow = {
    id: number;
    sequence: number;
    actor: string;
    action: string;
    targetType: string | null;
    targetId: string | null;
    result: string;
    at: string;
};

type AuditProps = {
    records: {
        data: AuditRow[];
        from: number | null;
        to: number | null;
        total: number;
        current_page: number;
        last_page: number;
    };
    ledger: { entries: number; accounts: number };
    chainVerification: { available: boolean; reason: string };
};

export default function AdminAudit({
    records,
    ledger,
    chainVerification,
}: AuditProps) {
    const rows = records.data ?? [];

    return (
        <>
            <Head title="Ledger & audit" />

            <div className="space-y-6">
                <NoticeBanner
                    tone={chainVerification.available ? 'success' : 'blocked'}
                >
                    {chainVerification.reason}
                </NoticeBanner>

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatTile
                        label="Audit records"
                        value={records.total ?? rows.length}
                        hint="Append-only, gapless sequence"
                        icon={<FileSearch className="size-4" />}
                    />
                    <StatTile
                        label="Ledger entries"
                        value={ledger.entries}
                        hint="Append-only, enforced by database triggers"
                        icon={<Lock className="size-4" />}
                    />
                    <StatTile
                        label="Ledger accounts"
                        value={ledger.accounts}
                        hint="Own the per-account sequence"
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Audit chain</CardTitle>
                        <CardDescription>
                            The audit trail is read-only. Every record carries a
                            gapless sequence number allocated under a row lock,
                            and a denial is recorded exactly like an approval.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {rows.length === 0 ? (
                            <p className="py-10 text-center text-sm text-muted-foreground">
                                No audit records yet.
                            </p>
                        ) : (
                            <DataTable
                                headers={[
                                    'Sequence',
                                    'Actor',
                                    'Action',
                                    'Target',
                                    'Result',
                                    'When',
                                ]}
                            >
                                {rows.map((record) => (
                                    <tr
                                        key={record.id}
                                        className="hover:bg-muted/30"
                                    >
                                        <td className="px-4 py-3 font-mono text-xs tabular-nums">
                                            {record.sequence}
                                        </td>
                                        <td className="px-4 py-3 font-mono text-xs">
                                            {record.actor}
                                        </td>
                                        <td className="px-4 py-3 font-medium">
                                            {record.action}
                                        </td>
                                        <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                                            {record.targetType
                                                ? `${record.targetType}${
                                                      record.targetId
                                                          ? `#${record.targetId}`
                                                          : ''
                                                  }`
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant="outline"
                                                className={cn(
                                                    'font-medium',
                                                    record.result === 'DENIED'
                                                        ? 'border-destructive/30 bg-destructive/10 text-destructive dark:text-red-400'
                                                        : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
                                                )}
                                            >
                                                {record.result}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                            {record.at}
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
