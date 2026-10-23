import { Head, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Camera,
    CalendarClock,
    Check,
    Clock,
    ImagePlus,
    Loader2,
    Send,
    Sparkles,
    Zap,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { BrandMark } from '@/components/brand-mark';
import { platformIcon } from '@/components/platform-icon';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { http } from '@/lib/http';
import { cn } from '@/lib/utils';
import {
    captions as captionsRoute,
    store as mediaStore,
} from '@/routes/business/media';
import { today as todayRoute } from '@/routes/business';
import { store as quickStore } from '@/routes/business/posts/quick';

type Account = {
    id: number;
    platform: string;
    platform_label: string;
    handle: string | null;
    display_name: string | null;
    connected: boolean;
};

type MediaAsset = {
    id: number;
    url: string;
    thumbnail_url: string | null;
    type: string | null;
};

type CaptionSuggestion = {
    caption: string;
    hashtags: string[];
    altText: string | null;
    firstComment: string | null;
};

type Props = {
    accounts: Account[];
    preloadAsset: MediaAsset | null;
    prefillCaption: string | null;
    suggestedSlot: { date: string; time: string; label: string };
    timezone: string;
    aiEnabled: boolean;
};

type When = 'next' | 'now' | 'schedule';

const DEFAULTS_KEY = 'alima.quick-post.accounts';

function rememberedAccounts(fallback: number[]): number[] {
    try {
        const raw = localStorage.getItem(DEFAULTS_KEY);

        if (!raw) {
            return fallback;
        }

        const ids = JSON.parse(raw) as number[];
        const kept = fallback.filter((id) => ids.includes(id));

        return kept.length > 0 ? kept : fallback;
    } catch {
        return fallback;
    }
}

export default function QuickPost({
    accounts,
    preloadAsset,
    prefillCaption,
    suggestedSlot,
    timezone,
    aiEnabled,
}: Props) {
    const connected = accounts.filter((account) => account.connected);
    const fileInput = useRef<HTMLInputElement>(null);

    const [asset, setAsset] = useState<MediaAsset | null>(preloadAsset);
    const [uploading, setUploading] = useState(false);
    const [caption, setCaption] = useState(prefillCaption ?? '');
    const [selected, setSelected] = useState<number[]>(() =>
        rememberedAccounts(connected.map((account) => account.id)),
    );
    const [when, setWhen] = useState<When>('next');
    const [date, setDate] = useState(suggestedSlot.date);
    const [time, setTime] = useState(suggestedSlot.time);
    const [suggesting, setSuggesting] = useState(false);
    const [suggestions, setSuggestions] = useState<CaptionSuggestion[]>([]);
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const upload = async (files: FileList | null) => {
        const file = files?.[0];

        if (!file) {
            return;
        }

        setUploading(true);
        setSuggestions([]);

        const form = new FormData();
        form.append('source', 'upload');
        form.append('file', file);

        const response = await http.post<{ asset: MediaAsset }>(
            mediaStore().url,
            form,
            {
                toastOnError: true,
            },
        );

        setUploading(false);

        if (fileInput.current) {
            fileInput.current.value = '';
        }

        if (response.ok) {
            setAsset(response.data.asset);
        }
    };

    const suggest = async () => {
        if (!asset || suggesting) {
            return;
        }

        setSuggesting(true);

        const response = await http.post<{ suggestions: CaptionSuggestion[] }>(
            captionsRoute({ asset: asset.id }).url,
            {},
            {
                toastOnError: true,
                rateLimitMessage: 'Give it a moment before asking again.',
            },
        );

        setSuggesting(false);

        if (response.ok) {
            setSuggestions(response.data.suggestions ?? []);

            if ((response.data.suggestions ?? []).length === 0) {
                toast.info(
                    'No captions came back — try a few words of your own.',
                );
            }
        }
    };

    const applySuggestion = (suggestion: CaptionSuggestion) => {
        const tags =
            suggestion.hashtags.length > 0
                ? '\n\n' + suggestion.hashtags.join(' ')
                : '';
        setCaption(suggestion.caption + tags);
        setSuggestions([]);
    };

    const toggleAccount = (id: number) => {
        setSelected((current) =>
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );
    };

    const submit = () => {
        if (submitting) {
            return;
        }

        try {
            localStorage.setItem(DEFAULTS_KEY, JSON.stringify(selected));
        } catch {
            // Non-fatal — just won't remember the default next time.
        }

        setSubmitting(true);
        setErrors({});

        router.post(
            quickStore().url,
            {
                asset_id: asset?.id ?? null,
                caption,
                account_ids: selected,
                when,
                scheduled_local_date: when === 'schedule' ? date : null,
                scheduled_local_time: when === 'schedule' ? time : null,
            },
            {
                onError: (bag) => setErrors(bag as Record<string, string>),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const canSubmit =
        asset !== null && caption.trim() !== '' && selected.length > 0;

    return (
        <div className="bg-background text-foreground min-h-dvh">
            <Head title="Quick post" />

            <header className="border-border/70 bg-background/90 sticky top-0 z-10 flex items-center gap-3 border-b px-4 py-3 backdrop-blur">
                <Button variant="ghost" size="icon" asChild>
                    <a href={todayRoute().url} aria-label="Back to Today">
                        <ArrowLeft />
                    </a>
                </Button>
                <div className="flex flex-1 items-center gap-2">
                    <BrandMark className="text-teal size-6" />
                    <span className="font-semibold">Quick post</span>
                </div>
            </header>

            <main className="mx-auto flex max-w-md flex-col gap-6 px-4 pt-5 pb-32">
                {/* 1 — media */}
                <section className="flex flex-col gap-3">
                    <StepLabel n={1} label="Your photo or video" />
                    <input
                        ref={fileInput}
                        type="file"
                        accept="image/*,video/*"
                        capture="environment"
                        className="hidden"
                        onChange={(event) => void upload(event.target.files)}
                    />
                    {asset ? (
                        <div className="group border-border relative overflow-hidden rounded-2xl border">
                            <img
                                src={asset.thumbnail_url ?? asset.url}
                                alt=""
                                className="aspect-square w-full object-cover"
                            />
                            <Button
                                type="button"
                                variant="on-ink"
                                size="sm"
                                className="absolute right-3 bottom-3"
                                disabled={uploading}
                                onClick={() => fileInput.current?.click()}
                            >
                                {uploading ? (
                                    <Loader2 className="animate-spin" />
                                ) : (
                                    <Camera />
                                )}
                                Change
                            </Button>
                        </div>
                    ) : (
                        <button
                            type="button"
                            disabled={uploading}
                            onClick={() => fileInput.current?.click()}
                            className="border-border bg-card hover:border-foreground/40 flex aspect-square w-full flex-col items-center justify-center gap-3 rounded-2xl border border-dashed transition-colors"
                        >
                            {uploading ? (
                                <>
                                    <Loader2 className="text-muted-foreground size-8 animate-spin" />
                                    <span className="text-muted-foreground text-sm">
                                        Uploading…
                                    </span>
                                </>
                            ) : (
                                <>
                                    <span className="bg-teal text-cream flex size-14 items-center justify-center rounded-2xl">
                                        <ImagePlus className="size-6" />
                                    </span>
                                    <span className="text-sm font-medium">
                                        Take a photo or pick one
                                    </span>
                                </>
                            )}
                        </button>
                    )}
                    {errors.asset_id && (
                        <p className="text-destructive text-sm">
                            {errors.asset_id}
                        </p>
                    )}
                </section>

                {/* 2 — caption */}
                <section className="flex flex-col gap-3">
                    <div className="flex items-center justify-between">
                        <StepLabel n={2} label="Caption" />
                        {aiEnabled && asset && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                disabled={suggesting}
                                onClick={suggest}
                            >
                                {suggesting ? (
                                    <Loader2 className="animate-spin" />
                                ) : (
                                    <Sparkles />
                                )}
                                Suggest
                            </Button>
                        )}
                    </div>
                    <Textarea
                        value={caption}
                        onChange={(event) => setCaption(event.target.value)}
                        placeholder="Say something about it…"
                        rows={4}
                    />
                    {errors.caption && (
                        <p className="text-destructive text-sm">
                            {errors.caption}
                        </p>
                    )}
                    {suggestions.length > 0 && (
                        <ul className="flex flex-col gap-2">
                            {suggestions.map((suggestion, index) => (
                                <li key={index}>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            applySuggestion(suggestion)
                                        }
                                        className="border-border bg-card hover:border-foreground/40 w-full rounded-xl border p-3 text-left text-sm transition-colors"
                                    >
                                        <span className="line-clamp-3">
                                            {suggestion.caption}
                                        </span>
                                        {suggestion.hashtags.length > 0 && (
                                            <span className="text-muted-foreground mt-1 block truncate text-xs">
                                                {suggestion.hashtags.join(' ')}
                                            </span>
                                        )}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {/* 3 — accounts */}
                <section className="flex flex-col gap-3">
                    <StepLabel n={3} label="Post to" />
                    {connected.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No connected accounts yet. Connect one from
                            Accounts, then come back.
                        </p>
                    ) : (
                        <div className="flex flex-wrap gap-2">
                            {connected.map((account) => {
                                const active = selected.includes(account.id);
                                const Icon = platformIcon(account.platform);

                                return (
                                    <button
                                        key={account.id}
                                        type="button"
                                        onClick={() =>
                                            toggleAccount(account.id)
                                        }
                                        aria-pressed={active}
                                        className={cn(
                                            'inline-flex items-center gap-2 rounded-full border px-3.5 py-2 text-sm font-medium transition-colors',
                                            active
                                                ? 'border-teal bg-teal text-cream'
                                                : 'border-border bg-card hover:border-foreground/40',
                                        )}
                                    >
                                        <Icon className="size-4" />
                                        {account.handle
                                            ? `@${account.handle}`
                                            : account.platform_label}
                                        {active && (
                                            <Check className="size-3.5" />
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    )}
                    {errors.account_ids && (
                        <p className="text-destructive text-sm">
                            {errors.account_ids}
                        </p>
                    )}
                </section>

                {/* 4 — when */}
                <section className="flex flex-col gap-3">
                    <StepLabel n={4} label="When" />
                    <div className="grid grid-cols-3 gap-2">
                        <WhenOption
                            icon={CalendarClock}
                            label="Next slot"
                            hint={suggestedSlot.label}
                            active={when === 'next'}
                            onClick={() => setWhen('next')}
                        />
                        <WhenOption
                            icon={Zap}
                            label="Now"
                            hint="Post right away"
                            active={when === 'now'}
                            onClick={() => setWhen('now')}
                        />
                        <WhenOption
                            icon={Clock}
                            label="Pick"
                            hint="Choose a time"
                            active={when === 'schedule'}
                            onClick={() => setWhen('schedule')}
                        />
                    </div>
                    {when === 'schedule' && (
                        <div className="grid grid-cols-2 gap-2">
                            <Input
                                type="date"
                                value={date}
                                onChange={(event) =>
                                    setDate(event.target.value)
                                }
                                aria-label="Date"
                            />
                            <Input
                                type="time"
                                value={time}
                                onChange={(event) =>
                                    setTime(event.target.value)
                                }
                                aria-label="Time"
                            />
                        </div>
                    )}
                    <p className="text-muted-foreground text-xs">
                        Times are in {timezone}.
                    </p>
                </section>
            </main>

            {/* Sticky action bar — the one tap that schedules it. */}
            <div className="border-border/70 bg-background/95 fixed inset-x-0 bottom-0 z-10 border-t px-4 py-3 backdrop-blur">
                <div className="mx-auto flex max-w-md">
                    <Button
                        variant="brand"
                        size="lg"
                        className="w-full"
                        disabled={!canSubmit || submitting}
                        onClick={submit}
                    >
                        {submitting ? (
                            <Loader2 className="animate-spin" />
                        ) : (
                            <Send />
                        )}
                        {when === 'now' ? 'Post now' : 'Schedule'}
                    </Button>
                </div>
            </div>
        </div>
    );
}

function StepLabel({ n, label }: { n: number; label: string }) {
    return (
        <div className="flex items-center gap-2">
            <span className="bg-muted text-muted-foreground flex size-5 items-center justify-center rounded-full text-xs font-semibold">
                {n}
            </span>
            <span className="text-sm font-semibold">{label}</span>
        </div>
    );
}

function WhenOption({
    icon: Icon,
    label,
    hint,
    active,
    onClick,
}: {
    icon: typeof CalendarClock;
    label: string;
    hint: string;
    active: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'flex flex-col items-start gap-1 rounded-xl border p-3 text-left transition-colors',
                active
                    ? 'border-teal bg-teal/5 ring-teal ring-1'
                    : 'border-border bg-card hover:border-foreground/40',
            )}
        >
            <Icon
                className={cn(
                    'size-5',
                    active ? 'text-teal' : 'text-muted-foreground',
                )}
            />
            <span className="text-sm font-medium">{label}</span>
            <span className="text-muted-foreground line-clamp-1 text-xs">
                {hint}
            </span>
        </button>
    );
}
