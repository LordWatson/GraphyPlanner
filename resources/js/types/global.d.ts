import type { PlatformRegistry } from '@/lib/platforms';
import type { Auth } from '@/types/auth';

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
            preview: boolean;
            unreadNotificationsCount: number;
            currency: string;
            /** Step M-2.1: the platform capability registry (`usePlatforms()`). */
            platforms: PlatformRegistry;
            [key: string]: unknown;
        };
    }
}
