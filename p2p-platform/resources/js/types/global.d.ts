import type { Auth } from '@/types/auth';
import type { P2PUser } from '@/p2p/types';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            /** The signed-in user's P2P profile, or null when signed out. */
            p2pUser: P2PUser | null;
            /** A short description of the current page, shown in the shell. */
            pageDescription: string;
            [key: string]: unknown;
        };
    }
}
