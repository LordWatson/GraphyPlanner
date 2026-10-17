import {
    AlertCircle,
    Check,
    Download,
    EyeOff,
    Maximize2,
    RefreshCw,
    Wand2,
    X,
} from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import type { StudioVariant } from '@/components/image-studio/types';
import { StatusPill } from '@/components/status-pill';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { download } from '@/routes/ai-image-variants';

export const REFINE_CHIPS = ['Brighter', 'Closer crop', 'More space for text'];

function TileAction({
    label,
    onClick,
    children,
    pressed,
    href,
}: {
    label: string;
    onClick?: () => void;
    children: React.ReactNode;
    pressed?: boolean;
    href?: string;
}) {
    const className =
        'bg-card/95 text-foreground hover:bg-card focus-visible:ring-ring flex size-11 items-center justify-center rounded-full shadow-sm outline-none focus-visible:ring-[3px] aria-pressed:bg-primary aria-pressed:text-primary-foreground';

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                {href ? (
                    <a href={href} aria-label={label} className={className}>
                        {children}
                    </a>
                ) : (
                    <button
                        type="button"
                        aria-label={label}
                        aria-pressed={pressed}
                        onClick={onClick}
                        className={className}
                    >
                        {children}
                    </button>
                )}
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}

/**
 * The studio's refine form: one input plus quick chips. Shared by the tile and the lightbox.
 */
export function RefineForm({
    onSubmit,
    onCancel,
    busy,
    autoFocus = true,
    className,
}: {
    onSubmit: (instruction: string) => void;
    onCancel: () => void;
    busy: boolean;
    autoFocus?: boolean;
    className?: string;
}) {
    const [value, setValue] = useState('');
    const inputRef = useRef<HTMLInputElement>(null);
    const inputId = useId();

    useEffect(() => {
        if (autoFocus) {
            inputRef.current?.focus();
        }
    }, [autoFocus]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (value.trim().length >= 2) {
            onSubmit(value.trim());
        }
    };

    return (
        <form
            onSubmit={submit}
            className={cn('flex flex-col gap-2', className)}
            onKeyDown={(event) => {
                if (event.key === 'Escape') {
                    event.stopPropagation();
                    onCancel();
                }
            }}
        >
            <label className="sr-only" htmlFor={inputId}>
                What should change?
            </label>
            <Input
                id={inputId}
                ref={inputRef}
                value={value}
                maxLength={300}
                onChange={(event) => setValue(event.target.value)}
                placeholder="e.g. warmer light"
            />
            <div className="flex flex-wrap gap-1.5">
                {REFINE_CHIPS.map((chip) => (
                    <button
                        key={chip}
                        type="button"
                        onClick={() => setValue(chip)}
                        className="border-border hover:bg-muted focus-visible:ring-ring rounded-full border px-2.5 py-1 text-xs outline-none focus-visible:ring-[3px]"
                    >
                        {chip}
                    </button>
                ))}
            </div>
            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onCancel}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    variant="secondary"
                    size="sm"
                    disabled={busy || value.trim().length < 2}
                >
                    {busy ? 'Refining…' : 'Refine'}
                </Button>
            </div>
        </form>
    );
}

/**
 * One image in a set, drawn at the set's ratio. Pending tiles are calm skeletons with a mono
 * "Drafting…" label; failed tiles say why in one friendly line. Actions show on hover/focus and
 * are always visible on touch screens. A `StatusPill` appears only while a tile isn't ready.
 */
export function ImageTile({
    variant,
    quotient,
    selected,
    canManage,
    busy,
    onToggleSelect,
    onOpen,
    onRefine,
    onRegenerate,
    onDiscard,
}: {
    variant: StudioVariant;
    quotient: number;
    selected: boolean;
    canManage: boolean;
    busy: boolean;
    onToggleSelect: () => void;
    onOpen: () => void;
    onRefine: (instruction: string) => void;
    onRegenerate: () => void;
    onDiscard: () => void;
}) {
    const [refining, setRefining] = useState(false);

    return (
        <figure
            className={cn(
                'group bg-muted relative overflow-hidden rounded-xl',
                selected &&
                    'ring-primary ring-offset-background ring-2 ring-offset-2',
                variant.discarded && 'opacity-60',
            )}
            style={{ aspectRatio: String(quotient) }}
        >
            {variant.ready && variant.previewUrl ? (
                <button
                    type="button"
                    onClick={onOpen}
                    className="focus-visible:ring-ring absolute inset-0 outline-none focus-visible:ring-[3px] focus-visible:ring-inset"
                    aria-label={`Open larger: ${variant.alt}`}
                >
                    <img
                        src={variant.previewUrl}
                        alt={variant.alt}
                        loading="lazy"
                        decoding="async"
                        className="size-full object-cover"
                    />
                </button>
            ) : variant.pending ? (
                <div className="bg-foreground/[0.06] flex size-full animate-pulse items-center justify-center motion-reduce:animate-none">
                    <span className="eyebrow text-muted-foreground">
                        {variant.kind === 'final'
                            ? 'Finishing…'
                            : variant.kind === 'refine'
                              ? 'Refining…'
                              : 'Drafting…'}
                    </span>
                </div>
            ) : (
                <div className="flex size-full flex-col items-center justify-center gap-2 p-4 text-center">
                    <AlertCircle className="text-destructive size-5" />
                    <p className="text-muted-foreground text-xs text-pretty">
                        {variant.error ??
                            "We couldn't make that one. Try again."}
                    </p>
                    {canManage && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={onRegenerate}
                            disabled={busy}
                        >
                            <RefreshCw />
                            Try again
                        </Button>
                    )}
                </div>
            )}

            <div className="pointer-events-none absolute inset-x-2 top-2 flex items-start justify-between gap-2">
                <div className="flex flex-wrap gap-1">
                    {!variant.ready && (
                        <StatusPill
                            kind="imageVariant"
                            value={variant.status}
                            label={variant.statusLabel}
                            className="bg-card/95"
                        />
                    )}
                    {variant.kind === 'final' && (
                        <Badge variant="electric">Final</Badge>
                    )}
                    {variant.kind === 'refine' && (
                        <Badge variant="lilac">Refined</Badge>
                    )}
                </div>
                {selected && (
                    <span className="bg-primary text-primary-foreground flex size-7 items-center justify-center rounded-full shadow-sm">
                        <Check className="size-4" aria-hidden />
                        <span className="sr-only">Selected</span>
                    </span>
                )}
            </div>

            {variant.ready && !refining && (
                <figcaption className="absolute inset-x-2 bottom-2 flex justify-center gap-1.5 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100 [@media(hover:none)]:opacity-100">
                    {canManage && (
                        <TileAction
                            label={selected ? 'Deselect' : 'Select'}
                            pressed={selected}
                            onClick={onToggleSelect}
                        >
                            <Check className="size-4" />
                        </TileAction>
                    )}
                    {canManage && (
                        <TileAction
                            label="Refine"
                            onClick={() => setRefining(true)}
                        >
                            <Wand2 className="size-4" />
                        </TileAction>
                    )}
                    {canManage && variant.tier === 'draft' && (
                        <TileAction label="Regenerate" onClick={onRegenerate}>
                            <RefreshCw className="size-4" />
                        </TileAction>
                    )}
                    <TileAction label="Open" onClick={onOpen}>
                        <Maximize2 className="size-4" />
                    </TileAction>
                    <TileAction
                        label="Download"
                        href={download.url(variant.id)}
                    >
                        <Download className="size-4" />
                    </TileAction>
                    {canManage && (
                        <TileAction
                            label={variant.discarded ? 'Restore' : 'Hide'}
                            onClick={onDiscard}
                        >
                            {variant.discarded ? (
                                <RefreshCw className="size-4" />
                            ) : (
                                <EyeOff className="size-4" />
                            )}
                        </TileAction>
                    )}
                </figcaption>
            )}

            {refining && (
                <div className="bg-card/95 absolute inset-x-2 bottom-2 rounded-xl p-3 shadow-md">
                    <div className="mb-2 flex items-center justify-between">
                        <span className="eyebrow text-muted-foreground">
                            Refine
                        </span>
                        <button
                            type="button"
                            aria-label="Close refine"
                            onClick={() => setRefining(false)}
                            className="text-muted-foreground hover:text-foreground"
                        >
                            <X className="size-4" />
                        </button>
                    </div>
                    <RefineForm
                        busy={busy}
                        onCancel={() => setRefining(false)}
                        onSubmit={(instruction) => {
                            onRefine(instruction);
                            setRefining(false);
                        }}
                    />
                </div>
            )}
        </figure>
    );
}
