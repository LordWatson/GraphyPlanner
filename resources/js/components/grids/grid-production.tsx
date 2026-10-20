import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import {
    CalendarClock,
    CalendarRange,
    FileStack,
    Loader2,
    PauseCircle,
    Send,
    TriangleAlert,
    UsersRound,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { confirmDialog } from '@/components/confirm-dialog';
import type { GridPlanData, GridReport } from '@/components/grids/types';
import { Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatGridMoment } from '@/lib/grid-layout';
import { hold, schedule, scheduleApproved } from '@/routes/grids';
import gridPosts from '@/routes/grids/posts';
import { preview as previewSchedule } from '@/routes/grids/schedule';

type Cadence = 'daily' | 'weekdays' | 'every_n_days';

type PreviewRow = {
    tile_id: number;
    position: number;
    sequence: number;
    date: string;
    time: string;
    publish_at: string;
    post_id: number | null;
    locked: boolean;
};

const WEEKDAYS = [
    { value: 1, label: 'Mon' },
    { value: 2, label: 'Tue' },
    { value: 3, label: 'Wed' },
    { value: 4, label: 'Thu' },
    { value: 5, label: 'Fri' },
    { value: 6, label: 'Sat' },
    { value: 7, label: 'Sun' },
];

/**
 * Step 18.10 — "Set dates": a start date, a cadence and a time, previewed (nothing is written)
 * in publish order, last position first, on the account's clock. Applying writes each tile's date
 * and its post's Instagram time.
 */
function ScheduleDialog({
    grid,
    open,
    onOpenChange,
    minGapMinutes,
}: {
    grid: GridPlanData;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    minGapMinutes: number;
}) {
    const tomorrow = new Date(Date.now() + 86_400_000)
        .toISOString()
        .slice(0, 10);
    const [start, setStart] = useState(tomorrow);
    const [time, setTime] = useState('09:00');
    const [cadence, setCadence] = useState<Cadence>('daily');
    const [weekdays, setWeekdays] = useState<number[]>([1, 3, 5]);
    const [every, setEvery] = useState(2);
    const [rows, setRows] = useState<PreviewRow[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const query = {
        start_date: start,
        time,
        cadence,
        ...(cadence === 'weekdays' ? { weekdays } : {}),
        ...(cadence === 'every_n_days' ? { every_n_days: every } : {}),
    };

    useEffect(() => {
        if (
            !open ||
            !start ||
            !time ||
            (cadence === 'weekdays' && weekdays.length === 0)
        ) {
            setRows(null);

            return;
        }

        const controller = new AbortController();
        setError(null);

        fetch(previewSchedule.url(grid.id, { query }), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
            credentials: 'same-origin',
        })
            .then(async (response) => {
                const json = (await response.json()) as {
                    rows?: PreviewRow[];
                    message?: string;
                };

                if (!response.ok) {
                    throw new Error(
                        json.message ?? 'That cadence doesn’t work.',
                    );
                }

                setRows(json.rows ?? []);
            })
            .catch((reason: unknown) => {
                if (!controller.signal.aborted) {
                    setRows(null);
                    setError(
                        reason instanceof Error
                            ? reason.message
                            : 'Couldn’t preview those dates.',
                    );
                }
            });

        return () => controller.abort();
    }, [open, start, time, cadence, weekdays.join(','), every]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Set the dates</DialogTitle>
                    <DialogDescription>
                        The last tile goes out first, so the profile builds in
                        the right order. Times are on the account’s clock (
                        {grid.account.timezone}).
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid grid-cols-2 gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="schedule-start">First post</Label>
                            <Input
                                id="schedule-start"
                                type="date"
                                value={start}
                                onChange={(event) =>
                                    setStart(event.target.value)
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="schedule-time">At</Label>
                            <Input
                                id="schedule-time"
                                type="time"
                                value={time}
                                onChange={(event) =>
                                    setTime(event.target.value)
                                }
                            />
                        </div>
                    </div>
                    <div className="grid gap-1.5">
                        <Label>How often</Label>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            size="sm"
                            value={cadence}
                            onValueChange={(value) =>
                                value && setCadence(value as Cadence)
                            }
                            className="flex-wrap justify-start"
                        >
                            <ToggleGroupItem value="daily">
                                Every day
                            </ToggleGroupItem>
                            <ToggleGroupItem value="weekdays">
                                Chosen days
                            </ToggleGroupItem>
                            <ToggleGroupItem value="every_n_days">
                                Every few days
                            </ToggleGroupItem>
                        </ToggleGroup>
                    </div>
                    {cadence === 'weekdays' && (
                        <ToggleGroup
                            type="multiple"
                            variant="outline"
                            size="sm"
                            value={weekdays.map(String)}
                            onValueChange={(values) =>
                                setWeekdays(
                                    values.map(Number).sort((a, b) => a - b),
                                )
                            }
                            className="flex-wrap justify-start"
                            aria-label="Days of the week"
                        >
                            {WEEKDAYS.map((day) => (
                                <ToggleGroupItem
                                    key={day.value}
                                    value={String(day.value)}
                                >
                                    {day.label}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                    )}
                    {cadence === 'every_n_days' && (
                        <div className="flex items-center gap-2 text-sm">
                            Every
                            <Input
                                type="number"
                                min={1}
                                max={30}
                                value={every}
                                onChange={(event) =>
                                    setEvery(Number(event.target.value) || 1)
                                }
                                className="w-20"
                                aria-label="Days between posts"
                            />
                            days
                        </div>
                    )}
                    {error && (
                        <p className="text-destructive text-sm">{error}</p>
                    )}
                    <ol className="border-border divide-border/70 max-h-64 divide-y overflow-y-auto rounded-xl border text-sm">
                        {rows === null ? (
                            <li className="text-muted-foreground flex items-center gap-2 p-3">
                                <Loader2 className="size-4 animate-spin" />
                                Working out the dates…
                            </li>
                        ) : (
                            rows.map((row) => (
                                <li
                                    key={row.tile_id}
                                    className="flex items-center justify-between gap-3 px-3 py-2"
                                >
                                    <span className="font-mono text-xs">
                                        {String(row.sequence).padStart(2, '0')}{' '}
                                        · Tile {row.position}
                                    </span>
                                    <span
                                        className={
                                            row.locked
                                                ? 'text-muted-foreground line-through'
                                                : ''
                                        }
                                    >
                                        {formatGridMoment(
                                            row.publish_at,
                                            grid.account.timezone,
                                        )}
                                    </span>
                                </li>
                            ))
                        )}
                    </ol>
                    <p className="text-muted-foreground text-xs">
                        Posts at least {minGapMinutes} minutes apart keep their
                        order. Posts that already went out keep their time.
                    </p>
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={saving || rows === null || rows.length === 0}
                        onClick={() => {
                            setSaving(true);
                            router.post(schedule.url(grid.id), query, {
                                preserveScroll: true,
                                onSuccess: () => onOpenChange(false),
                                onFinish: () => setSaving(false),
                            });
                        }}
                    >
                        <CalendarRange />
                        {saving ? 'Setting dates…' : 'Set these dates'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Steps 18.9–18.11 — from an approved grid to a live profile: make the posts, take their captions
 * through review together, date the sequence, schedule the approved posts, and — if a post fails or
 * leaves the schedule — hold the rest before the grid goes out of order. Every step is a click.
 */
export function GridProduction({
    grid,
    clientName,
    minGapMinutes,
}: {
    grid: GridPlanData;
    clientName: string;
    minGapMinutes: number;
}) {
    const [dating, setDating] = useState(false);
    const [report, setReport] = useState<GridReport | null>(null);
    const [busy, setBusy] = useState<string | null>(null);

    useEffect(
        () =>
            router.on('flash', (event) => {
                const flash = (event as CustomEvent).detail?.flash as
                    | { gridReport?: GridReport }
                    | undefined;

                if (flash?.gridReport && flash.gridReport.skipped.length > 0) {
                    setReport(flash.gridReport);
                }
            }),
        [],
    );

    const run = (
        key: string,
        url: string,
        data: Record<string, FormDataConvertible> = {},
    ) => {
        setBusy(key);
        router.post(url, data, {
            preserveScroll: true,
            onFinish: () => setBusy(null),
        });
    };

    const withoutPost = grid.tiles.filter((tile) => tile.post === null).length;
    const drafts = grid.tiles.filter(
        (tile) =>
            tile.post &&
            ['draft', 'changes_requested'].includes(tile.post.status),
    ).length;
    const inReview = grid.tiles.filter(
        (tile) => tile.post?.status === 'internal_review',
    ).length;
    const approved = grid.progress.approved;

    return (
        <Panel
            eyebrow="Production"
            title="From grid to profile"
            icon={FileStack}
            description="The look is approved. Each post still gets its caption approved the usual way."
        >
            <div className="grid gap-4">
                {grid.order_risk && (
                    <div
                        className="border-destructive/30 bg-destructive/5 grid gap-2 rounded-xl border p-3 text-sm"
                        role="alert"
                    >
                        <p className="flex items-center gap-2 font-medium">
                            <TriangleAlert className="text-destructive size-4" />
                            Grid order at risk
                        </p>
                        <p className="text-muted-foreground text-pretty">
                            {grid.order_risk.reason}{' '}
                            {grid.order_risk.later_post_ids.length} later{' '}
                            {grid.order_risk.later_post_ids.length === 1
                                ? 'post is'
                                : 'posts are'}{' '}
                            still scheduled and would go out first. Hold them
                            while you fix it. If something already went out of
                            order, Instagram’s “Reorder grid” (long-press a post
                            on the profile) can put it back.
                        </p>
                        <div>
                            <Button
                                type="button"
                                variant="destructive"
                                size="sm"
                                disabled={busy !== null}
                                onClick={async () => {
                                    if (
                                        await confirmDialog({
                                            title: 'Hold the rest?',
                                            description:
                                                'The later posts go back to approved (any the vendor is holding are recalled). Nothing else changes.',
                                            confirmLabel: 'Hold the rest',
                                            tone: 'destructive',
                                        })
                                    ) {
                                        run('hold', hold.url(grid.id));
                                    }
                                }}
                            >
                                <PauseCircle />
                                Hold the rest
                            </Button>
                        </div>
                    </div>
                )}

                {grid.sequence_warnings.length > 0 && (
                    <ul className="border-warning/30 bg-warning/10 grid gap-1 rounded-xl border p-3 text-sm">
                        {grid.sequence_warnings.map((warning) => (
                            <li
                                key={warning}
                                className="flex items-start gap-2"
                            >
                                <TriangleAlert className="text-warning mt-0.5 size-3.5 shrink-0" />
                                {warning}
                            </li>
                        ))}
                    </ul>
                )}

                <ol className="grid gap-3 sm:grid-cols-2">
                    <ProductionStep
                        number="01"
                        title="Make the posts"
                        hint={
                            withoutPost === 0
                                ? 'Every tile has its post.'
                                : `${withoutPost} ${withoutPost === 1 ? 'tile has' : 'tiles have'} no post yet.`
                        }
                    >
                        <Button
                            type="button"
                            size="sm"
                            disabled={withoutPost === 0 || busy !== null}
                            onClick={() =>
                                run('posts', gridPosts.store.url(grid.id))
                            }
                        >
                            <FileStack />
                            Make {withoutPost || ''} draft posts
                        </Button>
                    </ProductionStep>
                    <ProductionStep
                        number="02"
                        title="Set the dates"
                        hint="Last tile first, on the account’s clock."
                    >
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={busy !== null}
                            onClick={() => setDating(true)}
                        >
                            <CalendarRange />
                            Set dates
                        </Button>
                    </ProductionStep>
                    <ProductionStep
                        number="03"
                        title="Approve the captions"
                        hint={`${drafts} in draft · ${inReview} in review · ${approved} approved`}
                    >
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={drafts === 0 || busy !== null}
                                onClick={() =>
                                    run('submit', gridPosts.bulk.url(grid.id), {
                                        action: 'submit',
                                    })
                                }
                            >
                                <UsersRound />
                                Submit for review
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={inReview === 0 || busy !== null}
                                onClick={() =>
                                    run('send', gridPosts.bulk.url(grid.id), {
                                        action: 'send_to_client',
                                    })
                                }
                            >
                                <Send />
                                Send to {clientName}
                            </Button>
                        </div>
                    </ProductionStep>
                    <ProductionStep
                        number="04"
                        title="Schedule"
                        hint={`${grid.progress.scheduled} scheduled · ${grid.progress.live} live`}
                    >
                        <Button
                            type="button"
                            size="sm"
                            disabled={approved === 0 || busy !== null}
                            onClick={async () => {
                                if (
                                    await confirmDialog({
                                        title: `Schedule ${approved} approved ${approved === 1 ? 'post' : 'posts'}?`,
                                        description:
                                            'Each goes through its own checklist. Anything not ready is listed, never skipped quietly.',
                                        confirmLabel: 'Schedule',
                                    })
                                ) {
                                    run(
                                        'schedule',
                                        scheduleApproved.url(grid.id),
                                    );
                                }
                            }}
                        >
                            <CalendarClock />
                            Schedule approved posts
                        </Button>
                    </ProductionStep>
                </ol>
            </div>

            <ScheduleDialog
                grid={grid}
                open={dating}
                onOpenChange={setDating}
                minGapMinutes={minGapMinutes}
            />

            <Dialog
                open={report !== null}
                onOpenChange={(open) => !open && setReport(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{report?.title}</DialogTitle>
                        <DialogDescription>
                            These didn’t move. Here’s why.
                        </DialogDescription>
                    </DialogHeader>
                    <ul className="divide-border/70 grid divide-y text-sm">
                        {report?.skipped.map((item, index) => (
                            <li
                                key={index}
                                className="flex flex-col gap-0.5 py-2"
                            >
                                <span className="font-medium">
                                    {item.position
                                        ? `Tile ${item.position}`
                                        : `Post ${item.id ?? ''}`}
                                    {item.status ? (
                                        <span className="text-muted-foreground font-normal">
                                            {' '}
                                            · {item.status}
                                        </span>
                                    ) : null}
                                </span>
                                <span className="text-muted-foreground">
                                    {item.reason}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <DialogFooter>
                        <Button type="button" onClick={() => setReport(null)}>
                            Got it
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Panel>
    );
}

function ProductionStep({
    number,
    title,
    hint,
    children,
}: {
    number: string;
    title: string;
    hint: string;
    children: ReactNode;
}) {
    return (
        <li className="border-border flex flex-col gap-2 rounded-xl border p-3">
            <span className="flex items-baseline gap-2">
                <span className="text-muted-foreground font-mono text-[11px]">
                    {number}
                </span>
                <span className="text-sm font-semibold">{title}</span>
            </span>
            <span className="text-muted-foreground text-xs">{hint}</span>
            <div className="mt-auto pt-1">{children}</div>
        </li>
    );
}
