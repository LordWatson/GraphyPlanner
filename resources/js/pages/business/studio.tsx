import { Head } from '@inertiajs/react';
import {
    AlertCircle,
    BadgePercent,
    Clock,
    ImageIcon,
    Quote,
    Sparkles,
    UtensilsCrossed,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ImageLightbox } from '@/components/image-studio/image-lightbox';
import { ImageTile } from '@/components/image-studio/image-tile';
import {
    requestId,
    studioActions,
} from '@/components/image-studio/studio-actions';
import type {
    StudioFormat,
    StudioGeneration,
    StudioVariant,
} from '@/components/image-studio/types';
import { useGenerationPolling } from '@/components/image-studio/use-generation-polling';
import { ChapterHero, EmptyState, Page, Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatDateTime } from '@/lib/dates';
import { use as useImagesInPost } from '@/routes/ai-image-generations';
import {
    generate as generatePreset,
    index as studio,
} from '@/routes/business/studio';

const TEXT_LIMIT = 120;

type Preset = {
    key: string;
    label: string;
    tagline: string;
    fieldLabel: string;
    placeholder: string;
    defaultFormat: string;
};

type StudioData = {
    canGenerate: boolean;
    presets: Preset[];
    formats: StudioFormat[];
    maxVariants: number;
    quota: { used: number; quota: number | null; remaining: number | null };
    generations: StudioGeneration[];
};

/** Decorative icon per preset (keyed by the enum value the backend sends). */
const PRESET_ICONS: Record<string, LucideIcon> = {
    todays_special: UtensilsCrossed,
    opening_hours: Clock,
    sale_announcement: BadgePercent,
    quote_card: Quote,
};

/**
 * Step B-6.5 — the business shell's Image Studio. One-tap presets turn a single line of text into
 * an on-brand image through the shared Phase 16 engine. Owners/Managers generate; a Creator sees
 * the results read-only (and can still download). Data screen: calm, no Signal Orange.
 */
export default function Studio({ imageStudio }: { imageStudio: StudioData }) {
    const data = imageStudio;
    const generations = useGenerationPolling(data.generations);
    const latest = generations[0] as StudioGeneration | undefined;
    const earlier = generations.slice(1);
    const canManage = data.canGenerate;

    const [activePreset, setActivePreset] = useState<Preset | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [showHidden, setShowHidden] = useState(false);
    const [lightbox, setLightbox] = useState<{
        generationId: number;
        index: number;
    } | null>(null);

    const run = (
        action: (callbacks: {
            onError: (message: string) => void;
            onFinish: () => void;
        }) => void,
    ) => {
        setBusy(true);
        setError(null);
        action({ onError: setError, onFinish: () => setBusy(false) });
    };

    const toggleSelect = (variant: StudioVariant) =>
        setSelectedIds((current) =>
            current.includes(variant.id)
                ? current.filter((id) => id !== variant.id)
                : [...current, variant.id],
        );

    const useInPost = () => {
        const anchor = generations.find((generation) =>
            generation.variants.some((variant) =>
                selectedIds.includes(variant.id),
            ),
        );

        if (anchor === undefined) {
            return;
        }

        run((callbacks) =>
            studioActions.post(
                useImagesInPost.url(anchor.id),
                { variant_ids: selectedIds },
                { ...callbacks, fullReload: true },
            ),
        );
    };

    const lightboxGeneration = generations.find(
        (generation) => generation.id === lightbox?.generationId,
    );
    const lightboxVariants = (lightboxGeneration?.variants ?? []).filter(
        (variant) => variant.ready && (!variant.discarded || showHidden),
    );

    const renderGrid = (generation: StudioGeneration) => {
        const visible = generation.variants.filter(
            (variant) => showHidden || !variant.discarded,
        );
        const hiddenCount = generation.variants.length - visible.length;

        return (
            <div className="flex flex-col gap-3">
                <ul
                    className="grid grid-cols-2 gap-3 @3xl:grid-cols-4"
                    aria-label="Generated images"
                >
                    {visible.map((variant) => (
                        <li key={variant.id}>
                            <ImageTile
                                variant={variant}
                                quotient={generation.quotient}
                                selected={selectedIds.includes(variant.id)}
                                canManage={canManage}
                                busy={busy}
                                onToggleSelect={() => toggleSelect(variant)}
                                onOpen={() =>
                                    setLightbox({
                                        generationId: generation.id,
                                        index: Math.max(
                                            0,
                                            generation.variants
                                                .filter(
                                                    (v) =>
                                                        v.ready &&
                                                        (!v.discarded ||
                                                            showHidden),
                                                )
                                                .findIndex(
                                                    (v) => v.id === variant.id,
                                                ),
                                        ),
                                    })
                                }
                                onRefine={(instruction) =>
                                    run((callbacks) =>
                                        studioActions.refine(
                                            variant.id,
                                            instruction,
                                            callbacks,
                                        ),
                                    )
                                }
                                onRegenerate={() =>
                                    run((callbacks) =>
                                        studioActions.regenerate(
                                            variant.id,
                                            callbacks,
                                        ),
                                    )
                                }
                                onDiscard={() =>
                                    run((callbacks) =>
                                        studioActions.discard(
                                            variant.id,
                                            !variant.discarded,
                                            callbacks,
                                        ),
                                    )
                                }
                            />
                        </li>
                    ))}
                </ul>
                {hiddenCount > 0 && !showHidden && (
                    <button
                        type="button"
                        onClick={() => setShowHidden(true)}
                        className="text-muted-foreground hover:text-foreground w-fit text-xs underline underline-offset-4"
                    >
                        Show {hiddenCount} hidden
                    </button>
                )}
            </div>
        );
    };

    return (
        <Page>
            <Head title="Image studio" />

            <ChapterHero
                eyebrow="Image studio"
                title={
                    <>
                        Make it <em>visual</em>.
                    </>
                }
                description="Pick a starting point, add a line of text, and we'll draft a set of on-brand images in your style."
            />

            {error && (
                <div
                    role="alert"
                    className="border-destructive/30 bg-destructive/10 text-destructive flex items-start gap-2 rounded-xl border px-4 py-3 text-sm"
                >
                    <AlertCircle className="mt-0.5 size-4 shrink-0" />
                    {error}
                </div>
            )}

            {canManage && (
                <section aria-label="Start with a preset">
                    <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {data.presets.map((preset) => {
                            const Icon = PRESET_ICONS[preset.key] ?? ImageIcon;

                            return (
                                <li key={preset.key}>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setError(null);
                                            setActivePreset(preset);
                                        }}
                                        className="border-border bg-card hover:border-foreground/40 focus-visible:ring-ring flex size-full flex-col items-start gap-3 rounded-2xl border p-5 text-left transition-colors outline-none hover:shadow-sm focus-visible:ring-[3px]"
                                    >
                                        <span className="bg-muted text-foreground flex size-11 items-center justify-center rounded-xl">
                                            <Icon
                                                className="size-5"
                                                aria-hidden
                                            />
                                        </span>
                                        <span className="flex flex-col gap-1">
                                            <span className="font-medium">
                                                {preset.label}
                                            </span>
                                            <span className="text-muted-foreground text-sm text-pretty">
                                                {preset.tagline}
                                            </span>
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </section>
            )}

            <div className="@container flex min-w-0 flex-col gap-6">
                {latest === undefined ? (
                    <EmptyState
                        icon={ImageIcon}
                        title="No images yet."
                        description={
                            canManage
                                ? 'Pick a preset above and we’ll draft a set in your brand’s style.'
                                : 'Your owner or a manager can create images here.'
                        }
                        className="min-h-[18rem]"
                    />
                ) : (
                    <div className="flex flex-col gap-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="eyebrow text-muted-foreground">
                                Latest set · {latest.formatLabel}{' '}
                                {latest.format}
                                {latest.createdAt
                                    ? ` · ${formatDateTime(latest.createdAt)}`
                                    : ''}
                            </span>
                            {latest.status !== 'ready' && (
                                <StatusPill
                                    kind="imageGeneration"
                                    value={latest.status}
                                    label={latest.statusLabel}
                                />
                            )}
                        </div>
                        {renderGrid(latest)}
                    </div>
                )}

                {earlier.length > 0 && (
                    <div className="flex flex-col gap-3">
                        <span className="eyebrow text-muted-foreground">
                            Earlier sets
                        </span>
                        {earlier.map((generation) => (
                            <Panel
                                key={generation.id}
                                bodyClassName="flex flex-col gap-3"
                            >
                                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                                    <span className="font-mono text-xs tabular-nums">
                                        {generation.createdAt
                                            ? formatDateTime(
                                                  generation.createdAt,
                                              )
                                            : '—'}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {generation.formatLabel} ·{' '}
                                        {generation.variants.length} images
                                    </span>
                                </div>
                                {renderGrid(generation)}
                            </Panel>
                        ))}
                    </div>
                )}
            </div>

            {canManage && selectedIds.length > 0 && (
                <div className="bg-card border-border/80 sticky bottom-4 z-20 flex flex-wrap items-center justify-between gap-3 rounded-2xl border p-3 pl-5 shadow-md">
                    <span className="text-sm font-medium">
                        {selectedIds.length} selected
                    </span>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="ghost"
                            onClick={() => setSelectedIds([])}
                        >
                            Clear
                        </Button>
                        <Button onClick={useInPost} disabled={busy}>
                            Use in post
                        </Button>
                    </div>
                </div>
            )}

            <PresetDialog
                preset={activePreset}
                formats={data.formats}
                maxVariants={data.maxVariants}
                quota={data.quota}
                onClose={() => setActivePreset(null)}
            />

            <ImageLightbox
                variants={lightboxVariants}
                index={lightbox?.index ?? null}
                onIndexChange={(index) =>
                    setLightbox((current) =>
                        current ? { ...current, index } : current,
                    )
                }
                onClose={() => setLightbox(null)}
                selectedIds={selectedIds}
                canManage={canManage}
                busy={busy}
                caption={lightboxGeneration?.artDirection.headline ?? 'Image'}
                platform="instagram"
                handle=""
                onToggleSelect={toggleSelect}
                onRefine={(variant, instruction) =>
                    run((callbacks) =>
                        studioActions.refine(
                            variant.id,
                            instruction,
                            callbacks,
                        ),
                    )
                }
                onFinalize={(variant) =>
                    run((callbacks) =>
                        studioActions.finalize(variant.id, callbacks),
                    )
                }
            />
        </Page>
    );
}

function PresetDialog({
    preset,
    formats,
    maxVariants,
    quota,
    onClose,
}: {
    preset: Preset | null;
    formats: StudioFormat[];
    maxVariants: number;
    quota: StudioData['quota'];
    onClose: () => void;
}) {
    const [text, setText] = useState('');
    const [format, setFormat] = useState<string | null>(null);
    const [count, setCount] = useState(2);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Reset the fields whenever a different preset opens the dialog.
    const activeKey = preset?.key ?? null;
    useEffect(() => {
        if (preset) {
            setText('');
            setFormat(preset.defaultFormat);
            setCount(Math.min(2, maxVariants));
            setError(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [activeKey]);

    if (preset === null) {
        return null;
    }

    const outOfQuota = quota.remaining !== null && quota.remaining < count;
    const canSubmit = text.trim().length >= 2 && !submitting && !outOfQuota;

    const quotaLine =
        quota.quota === null
            ? `${count} drafts · no monthly limit`
            : `${count} drafts · ${quota.remaining} of ${quota.quota} images left this month`;

    const submit = () => {
        if (!canSubmit) {
            return;
        }

        setSubmitting(true);
        setError(null);
        let errored = false;
        studioActions.post(
            generatePreset.url(),
            {
                preset: preset.key,
                text: text.trim(),
                aspect_ratio: format,
                variant_count: count,
                request_id: requestId(),
            },
            {
                onError: (message) => {
                    errored = true;
                    setError(message);
                },
                onFinish: () => {
                    setSubmitting(false);

                    // The set started: close the dialog so the tiles fill in behind it.
                    if (!errored) {
                        onClose();
                    }
                },
            },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{preset.label}</DialogTitle>
                    <DialogDescription>{preset.tagline}</DialogDescription>
                </DialogHeader>

                {error && (
                    <div
                        role="alert"
                        className="border-destructive/30 bg-destructive/10 text-destructive flex items-start gap-2 rounded-xl border px-3 py-2 text-sm"
                    >
                        <AlertCircle className="mt-0.5 size-4 shrink-0" />
                        {error}
                    </div>
                )}

                <div className="flex flex-col gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="preset-text">{preset.fieldLabel}</Label>
                        <Textarea
                            id="preset-text"
                            rows={2}
                            maxLength={TEXT_LIMIT}
                            value={text}
                            placeholder={preset.placeholder}
                            onChange={(event) => setText(event.target.value)}
                        />
                        <span className="text-muted-foreground text-right font-mono text-[11px] tabular-nums">
                            {text.length}/{TEXT_LIMIT}
                        </span>
                    </div>

                    <div className="grid gap-2">
                        <Label>Format</Label>
                        <ToggleGroup
                            type="single"
                            value={format ?? preset.defaultFormat}
                            onValueChange={(value) => value && setFormat(value)}
                            aria-label="Image format"
                            className="flex-wrap justify-start gap-1"
                        >
                            {formats.map((option) => (
                                <ToggleGroupItem
                                    key={option.value}
                                    value={option.value}
                                    className="h-10 rounded-full! px-3 whitespace-nowrap"
                                >
                                    {option.label}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                    </div>

                    {maxVariants >= 4 && (
                        <div className="grid gap-2">
                            <Label>How many</Label>
                            <ToggleGroup
                                type="single"
                                value={String(count)}
                                onValueChange={(value) =>
                                    value && setCount(Number(value))
                                }
                                aria-label="How many images"
                                className="justify-start gap-1"
                            >
                                {[2, 4].map((option) => (
                                    <ToggleGroupItem
                                        key={option}
                                        value={String(option)}
                                        className="h-10 rounded-full! px-4"
                                    >
                                        {option} images
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                        </div>
                    )}
                </div>

                <DialogFooter className="flex-col gap-2 sm:flex-col sm:items-stretch">
                    <Button size="lg" onClick={submit} disabled={!canSubmit}>
                        <Sparkles />
                        {submitting ? 'Starting…' : 'Generate images'}
                    </Button>
                    <p className="text-muted-foreground text-center font-mono text-[11px] tabular-nums">
                        {outOfQuota
                            ? "You've used this month's images. Your owner can raise the limit."
                            : quotaLine}
                    </p>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

Studio.layout = {
    breadcrumbs: [{ title: 'Image studio', href: studio() }],
};
