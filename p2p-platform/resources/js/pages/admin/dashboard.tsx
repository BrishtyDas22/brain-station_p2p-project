import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    FileSearch,
    Gavel,
    RefreshCcw,
    ShieldCheck,
    Users,
    Wallet,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { NoticeBanner, StatTile } from '@/p2p/components';
import { formatMoney } from '@/p2p/money';

type AdminDashboardProps = {
    stats: {
        users: number;
        admins: number;
        kycPending: number;
        kycApproved: number;
        transactions: number;
        volumeMinor: number;
        underReview: number;
        reversals: number;
        disputes: number;
        settlementBatches: number;
    };
    recentUsers: {
        id: number;
        name: string;
        email: string;
        role: string;
        roleLabel: string;
        createdAt: string;
    }[];
    recentAudit: {
        sequence: number;
        actor: string;
        action: string;
        target: string | null;
        result: string;
        at: string;
    }[];
};

export default function AdminDashboard({
    stats,
    recentUsers,
    recentAudit,
}: AdminDashboardProps) {
    return (
        <>
            <Head title="Admin overview" />

            <div className="space-y-6">
                <NoticeBanner tone="info">
                    Every figure below is computed live from the database.
                    Settlement, reversal and dispute counts reflect recorded
                    rows — no simulation is involved.
                </NoticeBanner>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatTile
                        label="Registered users"
                        value={stats.users}
                        hint={`${stats.admins} administrator${stats.admins === 1 ? '' : 's'}`}
                        icon={<Users className="size-4" />}
                    />
                    <StatTile
                        label="KYC pending"
                        value={stats.kycPending}
                        hint={`${stats.kycApproved} approved`}
                        icon={<ShieldCheck className="size-4" />}
                        accent={stats.kycPending > 0 ? 'warning' : 'default'}
                    />
                    <StatTile
                        label="Transactions"
                        value={stats.transactions}
                        hint={`${stats.underReview} under review`}
                        icon={<Activity className="size-4" />}
                    />
                    <StatTile
                        label="Transferred volume"
                        value={formatMoney(stats.volumeMinor)}
                        hint="Excludes failed transfers"
                        icon={<Wallet className="size-4" />}
                        accent="primary"
                    />
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatTile
                        label="Reversals"
                        value={stats.reversals}
                        hint="Reversal requests on file"
                        icon={<RefreshCcw className="size-4" />}
                    />
                    <StatTile
                        label="Open disputes"
                        value={stats.disputes}
                        hint="Open or in review"
                        icon={<Gavel className="size-4" />}
                        accent={stats.disputes > 0 ? 'warning' : 'default'}
                    />
                    <StatTile
                        label="Settlement batches"
                        value={stats.settlementBatches}
                        hint="Batches opened"
                        icon={<RefreshCcw className="size-4" />}
                    />
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div>
                                    <CardTitle>Recent users</CardTitle>
                                    <CardDescription>
                                        The six most recent registrations.
                                    </CardDescription>
                                </div>
                                <Button asChild variant="outline" size="sm">
                                    <Link href="/admin/users">Manage</Link>
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent className="px-0">
                            {recentUsers.length === 0 ? (
                                <p className="px-6 py-8 text-center text-sm text-muted-foreground">
                                    No users registered yet.
                                </p>
                            ) : (
                                <ul className="divide-y">
                                    {recentUsers.map((user) => (
                                        <li
                                            key={user.id}
                                            className="flex items-center justify-between gap-4 px-6 py-3"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium">
                                                    {user.name}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {user.email}
                                                </p>
                                            </div>
                                            <div className="shrink-0 text-right">
                                                <p className="text-xs font-medium">
                                                    {user.roleLabel}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {user.createdAt}
                                                </p>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div>
                                    <CardTitle>Recent audit trail</CardTitle>
                                    <CardDescription>
                                        The last eight records in the
                                        append-only chain.
                                    </CardDescription>
                                </div>
                                <Button asChild variant="outline" size="sm">
                                    <Link href="/admin/audit">
                                        <FileSearch className="size-3.5" />
                                        Open
                                    </Link>
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent className="px-0">
                            {recentAudit.length === 0 ? (
                                <p className="px-6 py-8 text-center text-sm text-muted-foreground">
                                    No audit records yet.
                                </p>
                            ) : (
                                <ul className="divide-y">
                                    {recentAudit.map((record) => (
                                        <li
                                            key={record.sequence}
                                            className="flex items-center justify-between gap-4 px-6 py-3"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium">
                                                    {record.action}
                                                </p>
                                                <p className="truncate font-mono text-xs text-muted-foreground">
                                                    {record.actor}
                                                    {record.target
                                                        ? ` → ${record.target}`
                                                        : ''}
                                                </p>
                                            </div>
                                            <div className="shrink-0 text-right">
                                                <p
                                                    className={
                                                        record.result ===
                                                        'DENIED'
                                                            ? 'text-xs font-medium text-destructive'
                                                            : 'text-xs font-medium text-emerald-600 dark:text-emerald-400'
                                                    }
                                                >
                                                    {record.result}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {record.at}
                                                </p>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {stats.underReview > 0 && (
                    <Card className="border-amber-500/30 bg-amber-500/[0.04]">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-amber-700 dark:text-amber-300">
                                <AlertTriangle className="size-4" />
                                Attention required
                            </CardTitle>
                            <CardDescription>
                                {stats.underReview} transaction
                                {stats.underReview === 1 ? ' is' : 's are'}{' '}
                                flagged for review.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button asChild variant="outline" size="sm">
                                <Link href="/admin/monitoring">
                                    Open monitoring
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}
