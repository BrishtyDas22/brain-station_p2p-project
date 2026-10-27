import { Head } from '@inertiajs/react';
import { Bell, BellOff } from 'lucide-react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { NoticeBanner, EmptyState } from '@/p2p/components';
import { cn } from '@/lib/utils';

type Notification = {
    id: string;
    title: string;
    body: string;
    at: string;
    read: boolean;
    tone: 'info' | 'success' | 'warning' | 'danger';
};

type NotificationsProps = {
    notifications: Notification[];
    availability: { enabled: boolean; reason: string };
};

const TONE_STYLES: Record<Notification['tone'], string> = {
    info: 'bg-sky-500',
    success: 'bg-emerald-500',
    warning: 'bg-amber-500',
    danger: 'bg-destructive',
};

export default function Notifications({ notifications, availability }: NotificationsProps) {
    return (
        <>
            <Head title="Notifications" />

            <div className="space-y-6">
                <NoticeBanner tone="info">{availability.reason}</NoticeBanner>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Bell className="size-4" />
                            Alerts
                        </CardTitle>
                        <CardDescription>
                            Derived from your own transfer records.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="px-0">
                        {notifications.length === 0 ? (
                            <EmptyState
                                icon={<BellOff className="size-5" />}
                                title="Nothing to report"
                                description="No alerts have been raised for this account."
                            />
                        ) : (
                            <ul className="divide-y">
                                {notifications.map((notification) => (
                                    <li
                                        key={notification.id}
                                        className="flex items-start gap-3 px-6 py-4"
                                    >
                                        <span
                                            className={cn(
                                                'mt-1.5 size-2 shrink-0 rounded-full',
                                                TONE_STYLES[notification.tone],
                                            )}
                                            aria-hidden
                                        />
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium">
                                                {notification.title}
                                            </p>
                                            <p className="mt-0.5 text-sm text-muted-foreground">
                                                {notification.body}
                                            </p>
                                        </div>
                                        <p className="shrink-0 text-xs whitespace-nowrap text-muted-foreground">
                                            {notification.at}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
