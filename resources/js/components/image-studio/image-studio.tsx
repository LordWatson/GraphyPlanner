import { Link } from '@inertiajs/react';
import {
    AlertCircle,
    ChevronDown,
    ImageIcon,
    RotateCcw,
    Sparkles,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { ImageLightbox } from '@/components/image-studio/image-lightbox';
import { ImageTile } from '@/components/image-studio/image-tile';
import {
    requestId,
    studioActions,
} from '@/components/image-studio/studio-actions';
import type {
    ImageStudioData,
    StudioGeneration,
    StudioVariant,
} from '@/components/image-studio/types';
import { useGenerationPolling } from '@/components/image-studio/use-generation-polling';
import { EmptyState, Eyebrow, Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatDateTime } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { store as storeIdeaImages } from '@/routes/ai-post-ideas/images';
import { use as useImagesInPost } from '@/routes/ai-image-generations';
import { store as storeClientImages } from '@/routes/clients/ai/images';
import { edit as editBrandBrain } from '@/routes/clients/brand-brain';

const HEADLINE_LIMIT = 80;

type ArtFields = { subject: string; mood: string; style: string };

function initialFields(data: ImageStudioData): ArtFields {
    const latest = data.generations[0]?.artDirection;

    return {
        subject: latest?.subject ?? data.brandDefaults.subject,
        mood: latest?.mood ?? data.brandDefaults.mood,
        style: latest?.style ?? data.brandDefaults.style,
    };
}

/**
 * The AI Image Studio (Phase 16, Step 16.9): art direction on the left, the set filling in tile by
 * tile on the right, earlier sets as ruled rows below. One Primary action ("Generate images");
 * everything else is secondary. Designers get the grid read-only (download only). Data screen:
 * no Signal Orange, patterns or peeks.
 */
export function ImageStudio({
    data,
    hideHeader = false,
}: {
    data: ImageStudioData;
    /** The post editor shows its own collapsible section header, so the built-in one is hidden. */
    hideHeader?: boolean;
}) {
    const generations = useGenerationPolling(data.generations);
    const latest = generations[0] as StudioGeneration | undefined;
    const earlier = generations.slice(1);
    const canManage = data.canGenerate;

    const [fields, setFields] = useState<ArtFields>(() => initialFields(data));
    const [edited, setEdited] = useState<
        Partial<Record<keyof ArtFields, true>>
    >({});
    const [excluded, setExcluded] = useState<string[]>(
        latest?.artDirection.excludedColours ?? [],
    );
    const [withHeadline, setWithHeadline] = useState(
        latest?.artDirection.withHeadline ?? false,
    );
    const [headline, setHeadline] = useState(
        latest?.artDirection.headline ??
            data.target.title.slice(0, HEADLINE_LIMIT),
    );
    const [format, setFormat] = useState(latest?.format ?? data.defaultFormat);
    const [count, setCount] = useState(
        Math.min(latest?.variantCount ?? 4, data.maxVariants),
    );
    const [submitting, setSubmitting] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [lightbox, setLightbox] = useState<{
        generationId: number;
        index: number;
    } | null>(null);
    const [showHidden, setShowHidden] = useState(false);
    const [captionOpen, setCaptionOpen] = useState(false);

    const quota = data.quota;
    const outOfQuota = quota.remaining !== null && quota.remaining < count;
    const isRepeat =
        latest !== undefined &&
        Object.keys(edited).length === 0 &&
        latest.format === format &&
        latest.variantCount === count &&
        latest.artDirection.withHeadline === withHeadline &&
        [...latest.artDirection.excludedColours].sort().join() ===
            [...excluded].sort().join();

    const allVariants = useMemo(
        () => generations.flatMap((generation) => generation.variants),
        [generations],
    );
    const selectedVariants = allVariants.filter((variant) =>
        selectedIds.includes(variant.id),
    );

    const setField = (key: keyof ArtFields, value: string) => {
        setFields((current) => ({ ...current, [key]: value }));
        setEdited((current) => ({ ...current, [key]: true }));
    };

    const resetToBrand = () => {
        setFields({
            subject: data.brandDefaults.subject,
            mood: data.brandDefaults.mood,
            style: data.brandDefaults.style,
        });
        setEdited({});
        setExcluded([]);
        setWithHeadline(false);
    };

    const generate = () => {
        if (submitting) {
            return;
        }

        // Unedited fields resend this set's own overrides, so "Generate again" reuses the
        // cached art-direction brief; edited fields override the model.
        const base: Partial<Record<keyof ArtFields, string | null>> =
            latest?.overrides ?? {};
        const pick = (key: keyof ArtFields) =>
            edited[key] ? fields[key] : (base[key] ?? undefined);

        const url =
            data.target.type === 'idea' && data.target.ideaId !== null
                ? storeIdeaImages.url(data.target.ideaId)
                : storeClientImages.url(data.target.clientId);

        setSubmitting(true);
        setError(null);
        studioActions.post(
            url,
            {
                request_id: requestId(),
                aspect_ratio: format,
                variant_count: count,
                post_id:
                    data.target.type === 'post'
                        ? data.target.postId
                        : undefined,
                art_direction: {
                    subject: pick('subject'),
                    mood: pick('mood'),
                    style: pick('style'),
                    excluded_colours: excluded,
                },
                with_headline: withHeadline,
                headline: withHeadline ? headline : null,
            },
            {
                onError: setError,
                onFinish: () => {
                    setSubmitting(false);
                    setEdited({});
                },
            },
        );
    };

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
        // Selections can span sets; any set of this idea/post resolves the same draft post,
        // which the server then opens.
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

    // One request at a time: a new Inertia visit would cancel the one in flight.
    const makeSelectedFinal = () => {
        const queue = selectedVariants
            .filter((variant) => variant.tier === 'draft' && variant.ready)
            .map((variant) => variant.id);

        const next = () => {
            const id = queue.shift();

            if (id === undefined) {
                setBusy(false);
                setSelectedIds([]);

                return;
            }

            studioActions.finalize(id, { onError: setError, onFinish: next });
        };

        setBusy(true);
        setError(null);
        next();
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

    const quotaLine =
        quota.quota === null
            ? `${count} drafts · no monthly limit`
            : `${count} drafts · ${quota.remaining} of ${quota.quota} images left this month`;

    return (
        <section
            className="@container flex flex-col gap-6"
            aria-labelledby={hideHeader ? undefined : 'image-studio-title'}
            aria-label={hideHeader ? 'Image studio' : undefined}
        >
            {!hideHeader && (
                <header className="flex flex-col gap-2">
                    <Eyebrow>Image studio</Eyebrow>
                    <h2
                        id="image-studio-title"
                        className="font-display text-[2rem] leading-[1.05] tracking-[-0.02em] sm:text-[2.25rem]"
                    >
                        Make it{' '}
                        <em className="text-primary not-italic">visual</em>.
                    </h2>
                    {data.target.caption && (
                        <button
                            type="button"
                            aria-expanded={captionOpen}
                            onClick={() => setCaptionOpen((open) => !open)}
                            className="text-muted-foreground hover:text-foreground focus-visible:ring-ring flex w-full items-start gap-2 rounded-sm text-left text-sm outline-none focus-visible:ring-[3px]"
                        >
                            <span
                                className={cn(
                                    'border-primary border-l-2 pl-3 whitespace-pre-line',
                                    !captionOpen && 'line-clamp-1',
                                )}
                            >
                                “{data.target.caption}”
                            </span>
                            <ChevronDown
                                className={cn(
                                    'mt-0.5 size-4 shrink-0 transition-transform',
                                    captionOpen && 'rotate-180',
                                )}
                                aria-hidden
                            />
                            <span className="sr-only">
                                {captionOpen
                                    ? 'Collapse caption'
                                    : 'Show full caption'}
                            </span>
                        </button>
                    )}
                </header>
            )}

            {error && (
                <div
                    role="alert"
                    className="border-destructive/30 bg-destructive/10 text-destructive flex items-start gap-2 rounded-xl border px-4 py-3 text-sm"
                >
                    <AlertCircle className="mt-0.5 size-4 shrink-0" />
                    {error}
                </div>
            )}

            <div className={cn('grid gap-6', canManage && '@4xl:grid-cols-2')}>
                {canManage && (
                    <div className="flex flex-col gap-5">
                        <Panel
                            eyebrow="Art direction"
                            actions={
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={resetToBrand}
                                >
                                    <RotateCcw />
                                    Reset to brand
                                </Button>
                            }
                            bodyClassName="flex flex-col gap-4"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="studio-subject">Subject</Label>
                                <Textarea
                                    id="studio-subject"
                                    rows={3}
                                    className="field-sizing-content max-h-96"
                                    value={fields.subject}
                                    onChange={(event) =>
                                        setField('subject', event.target.value)
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="studio-mood">Mood</Label>
                                <Input
                                    id="studio-mood"
                                    maxLength={200}
                                    value={fields.mood}
                                    onChange={(event) =>
                                        setField('mood', event.target.value)
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="studio-style">Style</Label>
                                <Input
                                    id="studio-style"
                                    maxLength={300}
                                    value={fields.style}
                                    onChange={(event) =>
                                        setField('style', event.target.value)
                                    }
                                />
                            </div>

                            {data.palette.length > 0 && (
                                <fieldset className="grid gap-2">
                                    <legend className="mb-2 text-sm font-medium">
                                        Palette
                                    </legend>
                                    <div className="flex flex-wrap gap-2">
                                        {data.palette.map((colour) => {
                                            const off = excluded.includes(
                                                colour.name,
                                            );

                                            return (
                                                <button
                                                    key={colour.name}
                                                    type="button"
                                                    aria-pressed={!off}
                                                    onClick={() =>
                                                        setExcluded(
                                                            (current) =>
                                                                off
                                                                    ? current.filter(
                                                                          (
                                                                              name,
                                                                          ) =>
                                                                              name !==
                                                                              colour.name,
                                                                      )
                                                                    : [
                                                                          ...current,
                                                                          colour.name,
                                                                      ],
                                                        )
                                                    }
                                                    className={cn(
                                                        'border-border focus-visible:ring-ring inline-flex min-h-11 items-center gap-2 rounded-full border px-3 text-xs outline-none focus-visible:ring-[3px]',
                                                        off &&
                                                            'text-muted-foreground line-through opacity-60',
                                                    )}
                                                >
                                                    <span
                                                        aria-hidden
                                                        className="border-foreground/15 size-4 rounded-full border"
                                                        style={{
                                                            backgroundColor:
                                                                colour.hex ??
                                                                'transparent',
                                                        }}
                                                    />
                                                    {colour.name}
                                                </button>
                                            );
                                        })}
                                    </div>
                                    <p className="text-muted-foreground text-xs">
                                        Tap a colour to leave it out.
                                    </p>
                                </fieldset>
                            )}

                            <div className="grid gap-2">
                                <div className="flex items-center gap-3">
                                    <Checkbox
                                        id="studio-headline"
                                        checked={withHeadline}
                                        onCheckedChange={(checked) =>
                                            setWithHeadline(checked === true)
                                        }
                                    />
                                    <Label htmlFor="studio-headline">
                                        Add headline text
                                    </Label>
                                </div>
                                {withHeadline && (
                                    <div className="grid gap-1">
                                        <Input
                                            aria-label="Headline"
                                            maxLength={HEADLINE_LIMIT}
                                            value={headline}
                                            onChange={(event) =>
                                                setHeadline(event.target.value)
                                            }
                                        />
                                        <span className="text-muted-foreground text-right font-mono text-[11px] tabular-nums">
                                            {headline.length}/{HEADLINE_LIMIT}
                                        </span>
                                    </div>
                                )}
                            </div>

                            <div className="grid gap-2">
                                <span className="text-sm font-medium">
                                    References
                                </span>
                                {data.references.length > 0 ? (
                                    <ul className="flex flex-wrap gap-2">
                                        {data.references.map((reference) => (
                                            <li
                                                key={reference.assetId}
                                                className="flex flex-col items-start gap-1"
                                            >
                                                {reference.url && (
                                                    <img
                                                        src={reference.url}
                                                        alt={`${reference.roleLabel} reference`}
                                                        className="border-border size-14 rounded-lg border object-cover"
                                                    />
                                                )}
                                                <Badge
                                                    variant={
                                                        reference.role ===
                                                        'logo'
                                                            ? 'electric'
                                                            : 'lilac'
                                                    }
                                                >
                                                    {reference.roleLabel}
                                                </Badge>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-muted-foreground text-xs">
                                        No references yet.{' '}
                                        <Link
                                            href={`${editBrandBrain.url(data.target.clientId)}#references`}
                                            className="text-primary underline underline-offset-4"
                                        >
                                            Add a logo and a few posts you love
                                        </Link>
                                        .
                                    </p>
                                )}
                            </div>
                        </Panel>

                        <Panel
                            eyebrow="Format & size"
                            bodyClassName="flex flex-col gap-4"
                        >
                            <ToggleGroup
                                type="single"
                                value={format}
                                onValueChange={(value) =>
                                    value && setFormat(value)
                                }
                                aria-label="Image format"
                                className="flex-wrap justify-start gap-1 overflow-x-auto"
                            >
                                {data.formats.map((option) => (
                                    <ToggleGroupItem
                                        key={option.value}
                                        value={option.value}
                                        className="h-11 rounded-full! px-3.5 whitespace-nowrap"
                                    >
                                        {option.label}
                                        <span className="font-mono text-[11px] opacity-70">
                                            {option.value}
                                        </span>
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                            {data.maxVariants >= 4 && (
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
                                            className="h-11 rounded-full! px-4"
                                        >
                                            {option} images
                                        </ToggleGroupItem>
                                    ))}
                                </ToggleGroup>
                            )}

                            <div className="flex flex-col gap-2">
                                <Button
                                    size="lg"
                                    onClick={generate}
                                    disabled={submitting || outOfQuota}
                                >
                                    <Sparkles />
                                    {submitting
                                        ? 'Starting…'
                                        : isRepeat
                                          ? 'Generate again'
                                          : 'Generate images'}
                                </Button>
                                <p className="text-muted-foreground text-center font-mono text-[11px] tabular-nums">
                                    {outOfQuota
                                        ? "You've used this month's images. Your owner can raise the limit."
                                        : quotaLine}
                                </p>
                            </div>
                        </Panel>
                    </div>
                )}

                {/* Its own container, so the tile grid sizes to this column, not the section. */}
                <div className="@container flex min-w-0 flex-col gap-6">
                    {latest === undefined ? (
                        <EmptyState
                            icon={ImageIcon}
                            title="No images yet."
                            description="Pick a format and we'll draft a set in your client's style."
                            className="min-h-[24rem]"
                            action={
                                canManage ? (
                                    <Button
                                        onClick={generate}
                                        disabled={submitting || outOfQuota}
                                    >
                                        Generate images
                                    </Button>
                                ) : undefined
                            }
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
                        <div className="flex flex-col">
                            <span className="eyebrow text-muted-foreground mb-2">
                                Earlier sets
                            </span>
                            <ul className="border-foreground border-t">
                                {earlier.map((generation) => (
                                    <li
                                        key={generation.id}
                                        className="border-border/70 border-b"
                                    >
                                        <Collapsible>
                                            <CollapsibleTrigger className="hover:bg-muted/50 group flex w-full flex-wrap items-center gap-x-4 gap-y-1 py-3 text-left text-sm">
                                                <span className="font-mono text-xs tabular-nums">
                                                    {generation.createdAt
                                                        ? formatDateTime(
                                                              generation.createdAt,
                                                          )
                                                        : '—'}
                                                </span>
                                                <span>
                                                    {generation.formatLabel}{' '}
                                                    <span className="text-muted-foreground font-mono text-xs">
                                                        {generation.format}
                                                    </span>
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {generation.variants.length}{' '}
                                                    images
                                                </span>
                                                {generation.requestedBy && (
                                                    <span className="text-muted-foreground">
                                                        by{' '}
                                                        {generation.requestedBy}
                                                    </span>
                                                )}
                                                {generation.status !==
                                                    'ready' && (
                                                    <StatusPill
                                                        kind="imageGeneration"
                                                        value={
                                                            generation.status
                                                        }
                                                        label={
                                                            generation.statusLabel
                                                        }
                                                    />
                                                )}
                                                <ChevronDown
                                                    className="text-muted-foreground ml-auto size-4 transition-transform group-data-[state=open]:rotate-180"
                                                    aria-hidden
                                                />
                                            </CollapsibleTrigger>
                                            <CollapsibleContent className="pb-4">
                                                {renderGrid(generation)}
                                            </CollapsibleContent>
                                        </Collapsible>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
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
                        {selectedVariants.some(
                            (variant) => variant.tier === 'draft',
                        ) && (
                            <Button
                                variant="outline"
                                onClick={makeSelectedFinal}
                                disabled={busy}
                            >
                                Make final
                            </Button>
                        )}
                        <Button onClick={useInPost} disabled={busy}>
                            Use in post
                        </Button>
                    </div>
                </div>
            )}

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
                caption={data.target.caption ?? data.target.title}
                platform={(
                    data.target.platforms[0] ?? 'instagram'
                ).toLowerCase()}
                handle={data.target.clientName
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '')}
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
        </section>
    );
}
