import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Building2,
    Lock,
    Moon,
    RefreshCcw,
    ShieldCheck,
    Sun,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/hooks/use-appearance';

export default function Welcome() {
    const { auth } = usePage().props;
    const { resolvedAppearance, updateAppearance } = useAppearance();

    const features = [
        {
            icon: Building2,
            title: 'Double-entry ledger',
            body: 'Every transfer is recorded as balanced entries against a per-account sequence. The ledger is the authority; balances are a projection of it.',
        },
        {
            icon: ShieldCheck,
            title: 'Verified identity',
            body: 'KYC submissions are reviewed by an administrator and every decision is recorded in an append-only audit chain.',
        },
        {
            icon: Lock,
            title: 'Append-only by construction',
            body: 'Database triggers reject any UPDATE or DELETE against a ledger entry. Financial history cannot be rewritten.',
        },
        {
            icon: RefreshCcw,
            title: 'Atomic settlement',
            body: 'Transfers post inside a single database transaction with an idempotency key, so a retry can never double-spend.',
        },
    ];

    return (
        <>
            <Head title="P2P Settle — fund transfer & transaction settlement" />

            <div className="min-h-screen bg-background">
                {/* Header */}
                <header className="sticky top-0 z-30 border-b bg-background/90 backdrop-blur">
                    <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 lg:px-6">
                        <Link href="/" className="flex items-center gap-2.5">
                            <span className="flex size-8 items-center justify-center rounded-lg bg-primary text-sm font-bold text-primary-foreground">
                                P2P
                            </span>
                            <span className="text-sm font-semibold tracking-tight">
                                P2P Settle
                            </span>
                        </Link>

                        <nav className="flex items-center gap-1.5">
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

                            {auth.user ? (
                                <Button asChild>
                                    <Link href="/dashboard">
                                        Open dashboard
                                        <ArrowRight className="size-4" />
                                    </Link>
                                </Button>
                            ) : (
                                <>
                                    <Button asChild variant="ghost">
                                        <Link href="/login">Log in</Link>
                                    </Button>
                                    <Button asChild>
                                        <Link href="/register">Create account</Link>
                                    </Button>
                                </>
                            )}
                        </nav>
                    </div>
                </header>

                {/* Hero */}
                <section className="relative overflow-hidden">
                    <div
                        className="pointer-events-none absolute inset-0 bg-[radial-gradient(60%_50%_at_50%_0%,var(--color-primary)_0%,transparent_70%)] opacity-[0.07]"
                        aria-hidden
                    />
                    <div className="mx-auto max-w-6xl px-4 py-20 lg:px-6 lg:py-28">
                        <div className="mx-auto max-w-3xl text-center">
                            <span className="inline-flex items-center gap-2 rounded-full border bg-muted/40 px-3 py-1 text-xs font-medium text-muted-foreground">
                                <span className="size-1.5 rounded-full bg-emerald-500" />
                                Bangladesh · BDT · Dual-rail settlement
                            </span>

                            <h1 className="mt-6 text-4xl font-semibold tracking-tight text-balance sm:text-5xl lg:text-6xl">
                                Peer-to-peer fund transfer,
                                <br />
                                settled on a real ledger.
                            </h1>

                            <p className="mx-auto mt-5 max-w-2xl text-base text-pretty text-muted-foreground sm:text-lg">
                                A settlement platform built on an append-only,
                                double-entry ledger. Balances are never invented —
                                they are read from the database, and every transfer
                                leaves an auditable trail.
                            </p>

                            <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
                                {auth.user ? (
                                    <Button asChild size="lg">
                                        <Link href="/dashboard">
                                            Open dashboard
                                            <ArrowRight className="size-4" />
                                        </Link>
                                    </Button>
                                ) : (
                                    <>
                                        <Button asChild size="lg">
                                            <Link href="/register">
                                                Create an account
                                                <ArrowRight className="size-4" />
                                            </Link>
                                        </Button>
                                        <Button asChild size="lg" variant="outline">
                                            <Link href="/login">Log in</Link>
                                        </Button>
                                    </>
                                )}
                            </div>

                            <p className="mt-4 text-xs text-muted-foreground">
                                No credit card, no bank credentials, no external
                                payment integration.
                            </p>
                        </div>
                    </div>
                </section>

                {/* Features */}
                <section className="border-t bg-muted/20">
                    <div className="mx-auto max-w-6xl px-4 py-16 lg:px-6">
                        <div className="grid gap-6 sm:grid-cols-2">
                            {features.map((feature) => {
                                const Icon = feature.icon;

                                return (
                                    <div
                                        key={feature.title}
                                        className="rounded-xl border bg-background p-6"
                                    >
                                        <span className="flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                            <Icon className="size-5" />
                                        </span>
                                        <h2 className="mt-4 font-semibold">
                                            {feature.title}
                                        </h2>
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            {feature.body}
                                        </p>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </section>

                {/* Honest scope note */}
                <section className="border-t">
                    <div className="mx-auto max-w-6xl px-4 py-16 lg:px-6">
                        <div className="rounded-xl border bg-card p-8">
                            <h2 className="text-lg font-semibold">
                                What is live today
                            </h2>
                            <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                                Accounts, profiles, KYC, roles, wallets, transaction
                                history, the audit chain and the reconciliation checks
                                all read and write the real database. The transfer
                                engine itself is not yet implemented, so the send
                                and receive screens are clearly marked as not yet
                                available and refuse to move money. We would rather
                                show an honest gap than a convincing fake.
                            </p>
                        </div>
                    </div>
                </section>

                {/* Footer */}
                <footer className="border-t">
                    <div className="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-8 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between lg:px-6">
                        <p>P2P Settle — Laravel 13 · React 19 · Inertia · MySQL 8</p>
                        <p>Money is stored as integer Poisha. No floating-point.</p>
                    </div>
                </footer>
            </div>
        </>
    );
}
