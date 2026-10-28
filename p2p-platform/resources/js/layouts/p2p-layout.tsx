/**
 * The P2P platform shell.
 *
 * A sidebar + top-bar layout used by every authenticated customer and admin
 * page. Unlike the demo shell, this one reads the signed-in user from the
 * shared Inertia props, so the role, the KYC state and the name are all real
 * database values.
 */

import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    Bell,
    Building2,
    ChevronRight,
    CircleUser,
    FileSearch,
    Gavel,
    HandCoins,
    Landmark,
    LayoutDashboard,
    LogOut,
    Menu,
    Moon,
    Plus,
    RefreshCcw,
    Settings,
    ShieldCheck,
    Sun,
    Users,
    Wallet,
    X,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Sheet, SheetContent, SheetTrigger } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import { useAppearance } from '@/hooks/use-appearance';
import { KycBadge } from '@/p2p/components';
import { titleForPath } from '@/p2p/page-meta';
import type { P2PUser } from '@/p2p/types';

type P2PNavItem = {
    label: string;
    href: string;
    icon: typeof LayoutDashboard;
    section: 'personal' | 'admin';
};

/**
 * The platform navigation.
 *
 * The admin section is only rendered for a user whose database role is
 * ADMIN — the UI mirrors the server-side `admin` middleware, but the server
 * remains the authority.
 */
export const p2pNavItems: readonly P2PNavItem[] = [
    {
        label: 'Dashboard',
        href: '/dashboard',
        icon: LayoutDashboard,
        section: 'personal',
    },
    { label: 'Wallet', href: '/wallet', icon: Wallet, section: 'personal' },
    {
        label: 'Add Money',
        href: '/wallet/fundings',
        icon: Plus,
        section: 'personal',
    },
    {
        label: 'Withdraw Money',
        href: '/wallet/withdrawals',
        icon: Landmark,
        section: 'personal',
    },
    {
        label: 'Loan Marketplace',
        href: '/loans',
        icon: HandCoins,
        section: 'personal',
    },
    {
        label: 'My Loans',
        href: '/loans/my',
        icon: FileSearch,
        section: 'personal',
    },
    {
        label: 'My Investments',
        href: '/loans/investments',
        icon: Users,
        section: 'personal',
    },
    {
        label: 'Receive Money',
        href: '/receive',
        icon: RefreshCcw,
        section: 'personal',
    },
    {
        label: 'Transactions',
        href: '/transactions',
        icon: Activity,
        section: 'personal',
    },
    {
        label: 'KYC Verification',
        href: '/kyc',
        icon: ShieldCheck,
        section: 'personal',
    },
    {
        label: 'Notifications',
        href: '/notifications',
        icon: Bell,
        section: 'personal',
    },
    {
        label: 'Profile',
        href: '/settings/profile',
        icon: CircleUser,
        section: 'personal',
    },
    {
        label: 'Security',
        href: '/settings/security',
        icon: FileSearch,
        section: 'personal',
    },
    {
        label: 'Admin Overview',
        href: '/admin',
        icon: Building2,
        section: 'admin',
    },
    {
        label: 'User Management',
        href: '/admin/users',
        icon: Users,
        section: 'admin',
    },
    {
        label: 'KYC Review',
        href: '/admin/kyc',
        icon: FileSearch,
        section: 'admin',
    },
    {
        label: 'Monitoring',
        href: '/admin/monitoring',
        icon: Activity,
        section: 'admin',
    },
    {
        label: 'Settlement',
        href: '/admin/settlement',
        icon: RefreshCcw,
        section: 'admin',
    },
    {
        label: 'Reversals & Disputes',
        href: '/admin/reversals',
        icon: Gavel,
        section: 'admin',
    },
    {
        label: 'Ledger & Audit',
        href: '/admin/audit',
        icon: FileSearch,
        section: 'admin',
    },
    {
        label: 'Reconciliation',
        href: '/admin/reconciliation',
        icon: RefreshCcw,
        section: 'admin',
    },
];

function P2PSidebarNav({
    activePath,
    isAdmin,
    onNavigate,
}: {
    activePath: string;
    isAdmin: boolean;
    onNavigate?: () => void;
}) {
    const personal = p2pNavItems.filter((item) => item.section === 'personal');
    const admin = p2pNavItems.filter((item) => item.section === 'admin');

    // A prefix match keeps a child page highlighted (e.g. /transactions/12), but
    // several nav items can match the same path — /wallet/fundings is a child of
    // both "/wallet" and "/wallet/fundings". Only the MOST SPECIFIC match is
    // active, so the active item is never duplicated.
    const matches = (item: P2PNavItem) =>
        activePath === item.href ||
        (item.href !== '/admin' && activePath.startsWith(item.href + '/'));

    const activeHref = [...personal, ...admin]
        .filter(matches)
        .sort((a, b) => b.href.length - a.href.length)[0]?.href;

    const renderItem = (item: P2PNavItem) => {
        const Icon = item.icon;

        const active = item.href === activeHref;

        return (
            <Link
                key={item.href}
                href={item.href}
                onClick={onNavigate}
                aria-current={active ? 'page' : undefined}
                className={cn(
                    'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                    active
                        ? // The active page owns the solid, high-contrast background.
                          'bg-primary text-primary-foreground shadow-sm hover:bg-primary'
                        : // A non-active item uses a muted, low-contrast hover so it can
                          // never be mistaken for the active page.
                          'text-muted-foreground hover:bg-muted hover:text-foreground',
                )}
            >
                <Icon className="size-4 shrink-0" />
                <span className="truncate">{item.label}</span>
            </Link>
        );
    };

    return (
        <nav className="flex flex-col gap-6 px-3 py-4">
            <div className="space-y-1">
                <p className="px-3 pb-1 text-[10px] font-bold tracking-widest text-muted-foreground/70 uppercase">
                    My Account
                </p>
                {personal.map(renderItem)}
            </div>

            {isAdmin && (
                <div className="space-y-1">
                    <p className="px-3 pb-1 text-[10px] font-bold tracking-widest text-muted-foreground/70 uppercase">
                        Administration
                    </p>
                    {admin.map(renderItem)}
                </div>
            )}
        </nav>
    );
}

function P2PBrand() {
    return (
        <Link href="/dashboard" className="flex items-center gap-2.5 px-3 py-1">
            <span className="flex size-8 items-center justify-center rounded-lg bg-primary text-sm font-bold text-primary-foreground">
                P2P
            </span>
            <span className="flex flex-col leading-none">
                <span className="text-sm font-semibold">P2P Settle</span>
                <span className="mt-0.5 text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                    Bangladesh · BDT
                </span>
            </span>
        </Link>
    );
}

function ThemeToggle() {
    const { resolvedAppearance, updateAppearance } = useAppearance();

    return (
        <Button
            variant="ghost"
            size="icon"
            aria-label="Toggle theme"
            onClick={() =>
                updateAppearance(
                    resolvedAppearance === 'dark' ? 'light' : 'dark',
                )
            }
        >
            {resolvedAppearance === 'dark' ? (
                <Sun className="size-4" />
            ) : (
                <Moon className="size-4" />
            )}
        </Button>
    );
}

/**
 * The shell used by every P2P page.
 */
export default function P2PLayout({
    children,
    actions,
}: {
    children: ReactNode;
    actions?: ReactNode;
}) {
    const { url } = usePage();
    const { p2pUser, pageDescription } = usePage<{
        p2pUser: P2PUser | null;
        pageDescription: string;
    }>().props;

    const activePath = url.split('?')[0];
    const title = titleForPath(activePath);
    const description = pageDescription ?? '';
    const [mobileOpen, setMobileOpen] = useState(false);

    useEffect(() => {
        setMobileOpen(false);
    }, [activePath]);

    const isAdmin = p2pUser?.role === 'ADMIN';

    return (
        <div className="flex min-h-screen bg-background">
            {/* Desktop sidebar */}
            <aside className="hidden w-64 shrink-0 flex-col border-r bg-sidebar lg:flex">
                <div className="flex h-14 items-center border-b px-3">
                    <P2PBrand />
                </div>
                <div className="flex-1 overflow-y-auto">
                    <P2PSidebarNav activePath={activePath} isAdmin={isAdmin} />
                </div>
                <div className="border-t p-3">
                    <Link
                        href="/"
                        className="flex items-center gap-2 rounded-md px-3 py-2 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                    >
                        <ChevronRight className="size-3.5" />
                        Back to marketing site
                    </Link>
                </div>
            </aside>

            {/* Main column */}
            <div className="flex min-w-0 flex-1 flex-col">
                <header className="sticky top-0 z-30 flex h-14 items-center gap-3 border-b bg-background/95 px-4 backdrop-blur supports-[backdrop-filter]:bg-background/70 lg:px-6">
                    <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
                        <SheetTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="lg:hidden"
                                aria-label="Open navigation"
                            >
                                <Menu className="size-5" />
                            </Button>
                        </SheetTrigger>
                        <SheetContent
                            side="left"
                            className="w-72 p-0"
                            aria-label="Main navigation"
                        >
                            <div className="flex h-14 items-center justify-between border-b px-3">
                                <P2PBrand />
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    onClick={() => setMobileOpen(false)}
                                    aria-label="Close navigation"
                                >
                                    <X className="size-4" />
                                </Button>
                            </div>
                            <div className="overflow-y-auto">
                                <P2PSidebarNav
                                    activePath={activePath}
                                    isAdmin={isAdmin}
                                    onNavigate={() => setMobileOpen(false)}
                                />
                            </div>
                        </SheetContent>
                    </Sheet>

                    <div className="hidden min-w-0 flex-1 items-center gap-2 lg:flex">
                        <h1 className="truncate text-sm font-semibold">
                            {title}
                        </h1>
                        {description && (
                            <span className="truncate text-xs text-muted-foreground">
                                — {description}
                            </span>
                        )}
                    </div>

                    <div className="flex flex-1 items-center justify-end gap-1 lg:flex-none">
                        {actions}
                        <ThemeToggle />

                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Account menu"
                                >
                                    <span className="flex size-7 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground">
                                        {p2pUser?.initials ?? 'P2'}
                                    </span>
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-60">
                                <DropdownMenuLabel>
                                    <span className="block text-sm">
                                        {p2pUser?.name ?? 'Signed in'}
                                    </span>
                                    <span className="block text-xs font-normal text-muted-foreground">
                                        {p2pUser?.email ?? ''}
                                    </span>
                                    <span className="mt-2 flex items-center gap-2">
                                        <KycBadge
                                            status={
                                                (p2pUser?.kycStatus ??
                                                    'NOT_SUBMITTED') as never
                                            }
                                        />
                                        {isAdmin && (
                                            <span className="text-[10px] font-bold tracking-wide text-primary uppercase">
                                                {p2pUser?.roleLabel}
                                            </span>
                                        )}
                                    </span>
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem asChild>
                                    <Link href="/settings/profile">
                                        <Settings className="size-4" />
                                        Profile
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <Link href="/settings/security">
                                        <ShieldCheck className="size-4" />
                                        Security
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem asChild>
                                    <Link
                                        href="/logout"
                                        method="post"
                                        as="button"
                                    >
                                        <LogOut className="size-4" />
                                        Log out
                                    </Link>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </header>

                <main className="flex-1 px-4 py-6 lg:px-6">
                    <div className="mx-auto w-full max-w-7xl space-y-6">
                        <div className="lg:hidden">
                            <h1 className="text-xl font-semibold tracking-tight">
                                {title}
                            </h1>
                            {description && (
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {description}
                                </p>
                            )}
                        </div>
                        {children}
                    </div>
                </main>
            </div>
        </div>
    );
}
