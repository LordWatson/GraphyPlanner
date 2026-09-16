import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <div className="bg-primary text-primary-foreground flex aspect-square size-8 items-center justify-center rounded-md">
                <AppLogoIcon className="size-5 fill-current" />
            </div>
            <div className="ml-1 grid flex-1 text-left">
                <span className="truncate text-lg leading-tight font-semibold font-serif italic">
                    {name?.toString().toLowerCase()}.
                </span>
                <span className="truncate text-[10px] leading-tight font-medium tracking-widest text-sidebar-foreground/60 uppercase">
                    Agency OS
                </span>
            </div>
        </>
    );
}
