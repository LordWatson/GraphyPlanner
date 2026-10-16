import { Link } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

type Props = ComponentProps<typeof Link>;

export default function TextLink({
    className = '',
    children,
    ...props
}: Props) {
    return (
        <Link
            className={cn(
                'text-primary hover:text-primary-hover decoration-primary/40 font-medium underline decoration-2 underline-offset-4 transition-colors hover:decoration-current!',
                className,
            )}
            {...props}
        >
            {children}
        </Link>
    );
}
