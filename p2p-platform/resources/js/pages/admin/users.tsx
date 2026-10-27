import { Head, router, useForm } from '@inertiajs/react';
import { Search, ShieldCheck, Users } from 'lucide-react';
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
import { DataTable, KycBadge } from '@/p2p/components';
import type { KycStatus } from '@/p2p/types';

type UserRow = {
    id: number;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    kycStatus: KycStatus;
    kycLabel: string;
    emailVerified: boolean;
    createdAt: string;
};

type UsersProps = {
    users: {
        data: UserRow[];
        from: number | null;
        to: number | null;
        total: number;
        current_page: number;
        last_page: number;
    };
    filters: { search: string; role: string };
    roles: string[];
    totals: { users: number; admins: number };
};

export default function AdminUsers({ users, filters, roles, totals }: UsersProps) {
    const [search, setSearch] = useState(filters.search);
    const [role, setRole] = useState(filters.role);

    const apply = () => {
        router.get(
            '/admin/users',
            { search, role },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const rows = users.data ?? [];

    return (
        <>
            <Head title="User management" />

            <div className="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Users className="size-4" />
                            User directory
                        </CardTitle>
                        <CardDescription>
                            {totals.users} registered user{totals.users === 1 ? '' : 's'},{' '}
                            {totals.admins} with the administrator role. Only an
                            administrator can see this page, and only an
                            administrator can change a role.
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
                                    placeholder="Search by name or email…"
                                    className="pl-9"
                                    aria-label="Search users"
                                />
                            </div>

                            <Select
                                value={role}
                                onValueChange={(value) => {
                                    setRole(value);
                                    router.get(
                                        '/admin/users',
                                        { search, role: value },
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                            replace: true,
                                        },
                                    );
                                }}
                            >
                                <SelectTrigger className="w-[160px]" aria-label="Role">
                                    <SelectValue placeholder="All roles" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="ALL">All roles</SelectItem>
                                    {roles.map((option) => (
                                        <SelectItem key={option} value={option}>
                                            {option}
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
                        <CardTitle>Accounts</CardTitle>
                        <CardDescription>
                            Change a role by picking it from the row menu. The change is
                            written to the database and recorded in the audit chain.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {rows.length === 0 ? (
                            <p className="py-10 text-center text-sm text-muted-foreground">
                                No users match the current filter.
                            </p>
                        ) : (
                            <DataTable
                                headers={[
                                    'User',
                                    'Role',
                                    'KYC',
                                    'Email',
                                    'Registered',
                                    'Actions',
                                ]}
                            >
                                {rows.map((row) => (
                                    <tr key={row.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3">
                                            <p className="font-medium">{row.name}</p>
                                            <p className="text-xs text-muted-foreground">
                                                {row.email}
                                            </p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="text-sm">{row.roleLabel}</span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <KycBadge status={row.kycStatus} />
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="text-xs text-muted-foreground">
                                                {row.emailVerified
                                                    ? 'Verified'
                                                    : 'Unverified'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                            {row.createdAt}
                                        </td>
                                        <td className="px-4 py-3">
                                            <RoleControl user={row} />
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

/**
 * A role selector for one user.
 *
 * Posts to the server, which re-checks the administrator role before writing.
 */
function RoleControl({ user }: { user: UserRow }) {
    const form = useForm({ role: user.role });

    const change = (value: string) => {
        form.setData('role', value);
        form.patch(`/admin/users/${user.id}/role`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <div className="flex items-center gap-2">
            <Select value={user.role} onValueChange={change}>
                <SelectTrigger
                    className="h-8 w-[140px] text-xs"
                    aria-label={`Role for ${user.name}`}
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="USER">USER</SelectItem>
                    <SelectItem value="ADMIN">ADMIN</SelectItem>
                </SelectContent>
            </Select>

            {form.errors.role && (
                <span className="text-xs text-destructive">{form.errors.role}</span>
            )}

            {form.processing && (
                <ShieldCheck className="size-3.5 animate-pulse text-muted-foreground" />
            )}
        </div>
    );
}
