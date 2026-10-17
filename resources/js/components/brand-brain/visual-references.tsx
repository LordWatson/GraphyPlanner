import { router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    GripVertical,
    ImagePlus,
    X,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { EmptyState } from '@/components/page';
import { AssetLibraryDialog } from '@/components/post-editor/asset-library-dialog';
import type { AssetData } from '@/components/post-editor/types';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import { update as updateReferences } from '@/routes/clients/brand-brain/references';

export type ReferenceData = {
    asset_id: number;
    role: string;
    url: string | null;
    original_filename: string | null;
};

type RoleOption = { value: string; label: string };

/**
 * Brand Brain → Visual references (Step 16.4): up to `max` client images, each tagged Logo /
 * Style / Product, in the order the Image Studio should weigh them. Drag (or the arrow buttons)
 * to reorder; "Add from assets" reuses the paged asset library, locked to images.
 */
export function VisualReferences({
    clientId,
    initial,
    roles,
    max,
    canUpdate,
}: {
    clientId: number;
    initial: ReferenceData[];
    roles: RoleOption[];
    max: number;
    canUpdate: boolean;
}) {
    const [items, setItems] = useState<ReferenceData[]>(initial);
    const [browsing, setBrowsing] = useState(false);
    const [dragIndex, setDragIndex] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const dirty = JSON.stringify(items) !== JSON.stringify(initial);

    const move = (from: number, to: number) => {
        if (to < 0 || to >= items.length || from === to) {
            return;
        }

        setItems((current) => {
            const next = [...current];
            const [item] = next.splice(from, 1);
            next.splice(to, 0, item);

            return next;
        });
    };

    const addAssets = (assets: AssetData[]) => {
        setItems((current) => {
            const kept = current.filter((item) =>
                assets.some((asset) => asset.id === item.asset_id),
            );
            const added = assets
                .filter(
                    (asset) =>
                        !current.some((item) => item.asset_id === asset.id),
                )
                .map((asset, index) => ({
                    asset_id: asset.id,
                    // The first pick becomes the logo when there isn't one yet.
                    role:
                        index === 0 &&
                        !current.some((item) => item.role === 'logo')
                            ? 'logo'
                            : 'style',
                    url: asset.url,
                    original_filename: asset.original_filename,
                }));

            return [...kept, ...added].slice(0, max);
        });
    };

    const save = () => {
        setSaving(true);
        setError(null);
        router.put(
            updateReferences.url(clientId),
            {
                references: items.map((item) => ({
                    asset_id: item.asset_id,
                    role: item.role,
                })),
            },
            {
                preserveScroll: true,
                onError: (errors) =>
                    setError(
                        errors.references ??
                            Object.values(errors)[0] ??
                            'Could not save references.',
                    ),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <div className="flex flex-col gap-5">
            <p className="text-muted-foreground max-w-2xl text-sm text-pretty">
                The Image Studio grounds every image in these. Tag your logo,
                past posts you love (style) and real products. Up to {max}.
            </p>

            {items.length === 0 ? (
                <EmptyState
                    compact
                    icon={ImagePlus}
                    title="No references yet."
                    description="Add a logo and two or three posts you love. We'll match the look."
                    action={
                        canUpdate ? (
                            <Button onClick={() => setBrowsing(true)}>
                                Add references
                            </Button>
                        ) : undefined
                    }
                />
            ) : (
                <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    {items.map((item, index) => (
                        <li
                            key={item.asset_id}
                            draggable={canUpdate}
                            onDragStart={() => setDragIndex(index)}
                            onDragOver={(event) => event.preventDefault()}
                            onDrop={() => {
                                if (dragIndex !== null) {
                                    move(dragIndex, index);
                                }

                                setDragIndex(null);
                            }}
                            onDragEnd={() => setDragIndex(null)}
                            className={cn(
                                'border-border bg-card flex flex-col gap-2 rounded-xl border p-2',
                                dragIndex === index && 'opacity-50',
                            )}
                        >
                            <div className="bg-muted relative aspect-square overflow-hidden rounded-lg">
                                {item.url && (
                                    <img
                                        src={item.url}
                                        alt={
                                            item.original_filename ??
                                            'Reference image'
                                        }
                                        className="size-full object-cover"
                                    />
                                )}
                                <span className="bg-card/90 absolute top-1.5 left-1.5 flex size-6 items-center justify-center rounded-full font-mono text-[11px]">
                                    {index + 1}
                                </span>
                                {canUpdate && (
                                    <button
                                        type="button"
                                        aria-label={`Remove ${item.original_filename ?? 'reference'}`}
                                        onClick={() =>
                                            setItems((current) =>
                                                current.filter(
                                                    (other) =>
                                                        other.asset_id !==
                                                        item.asset_id,
                                                ),
                                            )
                                        }
                                        className="bg-card/90 border-border hover:bg-card focus-visible:ring-ring absolute top-1.5 right-1.5 flex size-8 items-center justify-center rounded-full border outline-none focus-visible:ring-[3px]"
                                    >
                                        <X className="size-4" />
                                    </button>
                                )}
                            </div>

                            <ToggleGroup
                                type="single"
                                size="sm"
                                value={item.role}
                                disabled={!canUpdate}
                                onValueChange={(role) =>
                                    role &&
                                    setItems((current) =>
                                        current.map((other) =>
                                            other.asset_id === item.asset_id
                                                ? { ...other, role }
                                                : other,
                                        ),
                                    )
                                }
                                aria-label="Reference role"
                                className="w-full"
                            >
                                {roles.map((role) => (
                                    <ToggleGroupItem
                                        key={role.value}
                                        value={role.value}
                                        className="flex-1 px-2 text-xs"
                                    >
                                        {role.label}
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>

                            {canUpdate && items.length > 1 && (
                                <div className="text-muted-foreground flex items-center justify-between">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-8"
                                        aria-label="Move earlier"
                                        disabled={index === 0}
                                        onClick={() => move(index, index - 1)}
                                    >
                                        <ArrowLeft />
                                    </Button>
                                    <GripVertical
                                        className="size-4 cursor-grab"
                                        aria-hidden
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-8"
                                        aria-label="Move later"
                                        disabled={index === items.length - 1}
                                        onClick={() => move(index, index + 1)}
                                    >
                                        <ArrowRight />
                                    </Button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <InputError message={error ?? undefined} />

            {canUpdate && (
                <div className="flex flex-wrap items-center gap-3">
                    {items.length > 0 && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setBrowsing(true)}
                        >
                            <ImagePlus />
                            Add from assets
                        </Button>
                    )}
                    <Button
                        type="button"
                        onClick={save}
                        disabled={saving || !dirty}
                    >
                        {saving ? 'Saving…' : 'Save references'}
                    </Button>
                    <span className="text-muted-foreground font-mono text-xs tabular-nums">
                        {items.length}/{max}
                    </span>
                </div>
            )}

            <AssetLibraryDialog
                open={browsing}
                onOpenChange={setBrowsing}
                clientId={clientId}
                imagesOnly
                title="Add visual references"
                description={`Pick up to ${max} images: your logo, posts you love, real products.`}
                confirmLabel="Use"
                selected={items.map((item) => ({
                    id: item.asset_id,
                    url: item.url,
                    original_filename: item.original_filename,
                    type: 'image',
                }))}
                onConfirm={addAssets}
            />
        </div>
    );
}
