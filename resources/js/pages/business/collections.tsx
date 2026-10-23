import { Head, Link, router } from '@inertiajs/react';
import { Layers, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState, Page, PageHeader } from '@/components/page';
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
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { StatusPill } from '@/components/status-pill';
import { cn } from '@/lib/utils';
import {
    index as collectionsIndex,
    store as storeCollection,
} from '@/routes/business/collections';
import { index as postsIndex } from '@/routes/business/posts';

type Option = { value: string; label: string };

type Collection = {
    id: number;
    name: string;
    colour: string | null;
    status: string;
    status_label: string;
    posts_count: number;
};

const COLOUR_CLASS: Record<string, string> = {
    teal: 'bg-teal',
    lime: 'bg-lime',
    lilac: 'bg-lilac',
    electric: 'bg-electric',
    signal: 'bg-signal',
    ink: 'bg-ink',
};

export default function BusinessCollections({
    collections,
    colours,
    can,
}: {
    collections: Collection[];
    colours: Option[];
    can: { create: boolean };
}) {
    const [creating, setCreating] = useState(false);

    return (
        <Page>
            <Head title="Collections" />

            <PageHeader
                eyebrow="Group your posts"
                title={
                    <>
                        Your <em>collections</em>.
                    </>
                }
                description="Group related posts — a launch, a season, a theme — to keep your planning tidy."
                actions={
                    can.create && (
                        <Button
                            variant="brand"
                            onClick={() => setCreating(true)}
                        >
                            <Plus />
                            New collection
                        </Button>
                    )
                }
            />

            {collections.length === 0 ? (
                <EmptyState
                    icon={Layers}
                    title="No collections yet."
                    description="Create one to group posts around a launch or a theme."
                    action={
                        can.create && (
                            <Button
                                variant="brand"
                                onClick={() => setCreating(true)}
                            >
                                <Plus />
                                New collection
                            </Button>
                        )
                    }
                />
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {collections.map((collection) => (
                        <Link
                            key={collection.id}
                            href={
                                postsIndex({
                                    query: { campaign: collection.id },
                                }).url
                            }
                            className="group border-border/80 bg-card flex flex-col gap-4 rounded-2xl border p-5 shadow-sm transition-shadow hover:shadow-md"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <span
                                    aria-hidden
                                    className={cn(
                                        'size-3 rounded-[3px]',
                                        (collection.colour &&
                                            COLOUR_CLASS[collection.colour]) ??
                                            'bg-muted-foreground/40',
                                    )}
                                />
                                <StatusPill
                                    kind="campaign"
                                    value={collection.status}
                                    label={collection.status_label}
                                />
                            </div>
                            <div className="flex flex-col gap-1">
                                <span className="font-display text-lg leading-tight">
                                    {collection.name}
                                </span>
                                <span className="text-muted-foreground text-xs">
                                    {collection.posts_count}{' '}
                                    {collection.posts_count === 1
                                        ? 'post'
                                        : 'posts'}
                                </span>
                            </div>
                        </Link>
                    ))}
                </div>
            )}

            <CreateCollectionDialog
                open={creating}
                onOpenChange={setCreating}
                colours={colours}
            />
        </Page>
    );
}

function CreateCollectionDialog({
    open,
    onOpenChange,
    colours,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    colours: Option[];
}) {
    const [name, setName] = useState('');
    const [colour, setColour] = useState<string>('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const submit = () => {
        setSaving(true);

        router.post(
            storeCollection().url,
            { name, colour: colour || undefined },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    setName('');
                    setColour('');
                    setErrors({});
                },
                onError: (formErrors) => setErrors(formErrors),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New collection</DialogTitle>
                    <DialogDescription>
                        Give it a name — you can add posts to it from the
                        editor.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-4 py-2">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            placeholder="Spring launch"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label>Colour</Label>
                        <div className="flex flex-wrap gap-2">
                            {colours.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    aria-label={option.label}
                                    aria-pressed={colour === option.value}
                                    onClick={() => setColour(option.value)}
                                    className={cn(
                                        'size-7 rounded-full ring-offset-2 transition-[box-shadow]',
                                        COLOUR_CLASS[option.value] ??
                                            'bg-muted-foreground/40',
                                        colour === option.value &&
                                            'ring-ring ring-2',
                                    )}
                                />
                            ))}
                        </div>
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="brand"
                        disabled={saving || !name}
                        onClick={submit}
                    >
                        Create
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

BusinessCollections.layout = {
    breadcrumbs: [{ title: 'Collections', href: collectionsIndex() }],
};
