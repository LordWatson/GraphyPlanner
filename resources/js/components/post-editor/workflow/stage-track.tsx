import { Check } from 'lucide-react';
import { stageIndexFor, workflowStages } from '@/lib/post-workflow';
import { resolveStatus, toneBgClass, toneTextClass } from '@/lib/status';
import { cn } from '@/lib/utils';

/**
 * The lifecycle at a glance: done stages get a check, the current one carries its real status
 * tone (so "Changes requested" and "Failed" read differently from the happy path), upcoming ones
 * stay quiet. Archived posts render the whole track dimmed.
 */
export function StageTrack({ status }: { status: string }) {
    const currentIndex = stageIndexFor(status);
    const tone = resolveStatus('post', status).tone;
    const archived = currentIndex === -1;

    return (
        <ol
            className={cn(
                '-mx-1 flex min-w-0 items-start overflow-x-auto px-1 pb-1',
                archived && 'opacity-45',
            )}
            aria-label="Post workflow"
        >
            {workflowStages.map((stage, index) => {
                const done = !archived && index < currentIndex;
                const current = index === currentIndex;
                const isLast = index === workflowStages.length - 1;

                return (
                    <li
                        key={stage.key}
                        className="flex min-w-[6.5rem] flex-1 flex-col gap-2"
                        aria-current={current ? 'step' : undefined}
                    >
                        <div className="flex items-center">
                            <span
                                className={cn(
                                    'flex size-7 shrink-0 items-center justify-center rounded-full border text-[11px] font-medium transition-colors',
                                    done &&
                                        'bg-foreground text-background border-transparent',
                                    current &&
                                        'border-foreground/15 bg-card ring-4 ring-current/15',
                                    current && toneTextClass[tone],
                                    !done &&
                                        !current &&
                                        'border-border bg-background text-muted-foreground font-mono',
                                )}
                            >
                                {done ? (
                                    <Check className="size-3.5" />
                                ) : current ? (
                                    <span
                                        className={cn(
                                            'size-2.5 rounded-full',
                                            toneBgClass[tone],
                                            status === 'publishing' &&
                                                'animate-pulse',
                                        )}
                                    />
                                ) : (
                                    String(index + 1).padStart(2, '0')
                                )}
                            </span>
                            {!isLast && (
                                <span
                                    aria-hidden
                                    className={cn(
                                        'mx-2 h-px flex-1',
                                        done ? 'bg-foreground/60' : 'bg-border',
                                    )}
                                />
                            )}
                        </div>
                        <div className="flex flex-col pr-3">
                            <span
                                className={cn(
                                    'text-[13px] leading-tight',
                                    current
                                        ? 'text-foreground font-semibold'
                                        : done
                                          ? 'text-foreground/80'
                                          : 'text-muted-foreground',
                                )}
                            >
                                {stage.label}
                            </span>
                            {current &&
                                stage.statuses.length > 1 &&
                                status !== stage.statuses[0] && (
                                    <span
                                        className={cn(
                                            'text-xs',
                                            toneTextClass[tone],
                                        )}
                                    >
                                        {resolveStatus('post', status).label}
                                    </span>
                                )}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
