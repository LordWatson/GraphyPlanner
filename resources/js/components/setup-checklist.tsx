import type { InertiaLinkProps } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { ArrowRight, Check } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * ux-overhaul MOVE 5 + business propagation — the shared "get set up" checklist card: a short guide
 * that walks the first few steps of a journey and dismisses itself once there is nothing left to do.
 * Used by the agency client page (`clients/client-setup-checklist.tsx`) and the business Today
 * dashboard (`business/brand-setup-checklist.tsx`).
 *
 * It renders only the visible steps, marks the first incomplete one as the next move (a solid CTA),
 * and returns null when every visible step is done or none is visible — so it never nags an
 * already-set-up account.
 */
export type SetupStep = {
    key: string;
    title: string;
    hint: string;
    cta: string;
    icon: LucideIcon;
    href: NonNullable<InertiaLinkProps['href']>;
    done: boolean;
    /** Default true; a step the viewer can't act on is left out and ignored in the progress. */
    visible?: boolean;
    /** For a step whose destination is a non-GET route (e.g. "create an empty draft" POST). */
    method?: InertiaLinkProps['method'];
};

export function SetupChecklist({
    eyebrow = 'Get started',
    title,
    steps,
    className,
}: {
    eyebrow?: ReactNode;
    title: ReactNode;
    steps: SetupStep[];
    className?: string;
}) {
    const visible = steps.filter((step) => step.visible !== false);
    const doneCount = visible.filter((step) => step.done).length;

    if (visible.length === 0 || doneCount === visible.length) {
        return null;
    }

    const nextKey = visible.find((step) => !step.done)?.key;

    return (
        <section
            aria-label="Set up checklist"
            className={cn(
                'border-border/80 bg-card animate-rise overflow-hidden rounded-2xl border shadow-sm',
                className,
            )}
        >
            <div className="border-border/70 flex flex-col gap-3 border-b px-5 pt-5 pb-4 sm:flex-row sm:items-end sm:justify-between">
                <div className="flex flex-col gap-1">
                    <span className="eyebrow text-muted-foreground">
                        {eyebrow}
                    </span>
                    <h2 className="font-display text-xl leading-tight">
                        {title}
                    </h2>
                </div>
                <div className="flex items-center gap-3">
                    <span className="text-muted-foreground font-mono text-xs tabular-nums">
                        {doneCount} / {visible.length} done
                    </span>
                    <span
                        aria-hidden
                        className="bg-muted h-1.5 w-24 overflow-hidden rounded-full"
                    >
                        <span
                            className="bg-primary block h-full rounded-full transition-[width]"
                            style={{
                                width: `${(doneCount / visible.length) * 100}%`,
                            }}
                        />
                    </span>
                </div>
            </div>

            <ol className="divide-border/70 divide-y">
                {visible.map((step, index) => {
                    const isNext = step.key === nextKey;

                    return (
                        <li
                            key={step.key}
                            className="flex items-center gap-4 px-5 py-3.5"
                        >
                            <span
                                className={cn(
                                    'flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold tabular-nums',
                                    step.done
                                        ? 'bg-success/15 text-success'
                                        : isNext
                                          ? 'bg-primary text-primary-foreground'
                                          : 'border-input text-muted-foreground border-2',
                                )}
                            >
                                {step.done ? (
                                    <Check className="size-4" />
                                ) : (
                                    index + 1
                                )}
                            </span>

                            <div className="flex min-w-0 flex-1 flex-col">
                                <span
                                    className={cn(
                                        'flex items-center gap-2 text-sm font-medium',
                                        step.done && 'text-muted-foreground',
                                    )}
                                >
                                    <step.icon className="size-3.5 shrink-0 opacity-60" />
                                    {step.title}
                                </span>
                                <span className="text-muted-foreground truncate text-xs">
                                    {step.hint}
                                </span>
                            </div>

                            {step.done ? (
                                <span className="text-success inline-flex shrink-0 items-center gap-1 text-xs font-medium">
                                    <Check className="size-3.5" />
                                    Done
                                </span>
                            ) : (
                                <Button
                                    size="sm"
                                    variant={isNext ? 'default' : 'outline'}
                                    className="shrink-0"
                                    asChild
                                >
                                    <Link
                                        href={step.href}
                                        method={step.method}
                                        as={
                                            step.method && step.method !== 'get'
                                                ? 'button'
                                                : undefined
                                        }
                                    >
                                        {step.cta}
                                        <ArrowRight />
                                    </Link>
                                </Button>
                            )}
                        </li>
                    );
                })}
            </ol>
        </section>
    );
}
