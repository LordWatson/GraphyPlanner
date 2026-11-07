import { Head, Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';
import {
    ArrowDown,
    ArrowUp,
    Eye,
    EyeOff,
    Link2,
    Plus,
    Sparkles,
    Trash2,
} from 'lucide-react';
import {
    BioPageView,
    type BioBlockPayload,
    type BioPagePayload,
} from '@/components/bio/bio-page-view';
import { ChapterHero, EmptyState, Page, Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { edit as editBio, update } from '@/routes/business/bio';
import {
    destroy as destroyDomain,
    store as storeDomain,
    verify as verifyDomain,
} from '@/routes/business/bio/domains';
import { edit as editPlan } from '@/routes/plan';

type Option = { value: string; label: string };

type BlockForm = {
    id?: number;
    type: string;
    is_visible: boolean;
    settings: {
        label?: string;
        url?: string;
        limit?: number;
        product_id?: number;
    };
};

type PageForm = {
    handle: string;
    title: string;
    bio: string;
    theme: string;
    is_published: boolean;
    blocks: BlockForm[];
};

/**
 * Step CG-3.2 — the link-in-bio editor. The whole page is one form: edit the details, add, edit,
 * hide, reorder and remove blocks, then save once (the server syncs it all in one transaction).
 * The preview beside it renders the real public component from the form state, so it is live.
 *
 * Reorder is up/down buttons rather than drag: this shell is phone-first, where HTML5 drag doesn't
 * work, and arrows are also the keyboard-accessible path.
 */
type Domain = {
    id: number;
    hostname: string;
    verified: boolean;
    ssl_status: string;
    last_error: string | null;
    verification_token: string;
    last_checked_at: string | null;
};

export default function BusinessBioPage({
    page,
    publicPreview,
    suggestedHandle,
    themes,
    blockTypes,
    products,
    domains,
    domainTarget,
    maxDomains,
    entitled,
    domainsEntitled,
    canManage,
}: {
    page: (PageForm & { public_url: string }) | null;
    publicPreview?: BioPagePayload | null;
    suggestedHandle: string;
    themes: Option[];
    blockTypes: Option[];
    products: { id: number; title: string }[];
    domains: Domain[];
    domainTarget: string;
    maxDomains: number;
    entitled: boolean;
    domainsEntitled: boolean;
    canManage: boolean;
}) {
    const [form, setForm] = useState<PageForm>({
        handle: page?.handle ?? suggestedHandle,
        title: page?.title ?? '',
        bio: page?.bio ?? '',
        theme: page?.theme ?? 'cream',
        is_published: page?.is_published ?? false,
        blocks: page?.blocks ?? [],
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [newDomain, setNewDomain] = useState('');

    const set = <K extends keyof PageForm>(key: K, value: PageForm[K]) =>
        setForm((current) => ({ ...current, [key]: value }));

    const setBlock = (index: number, block: BlockForm) =>
        set(
            'blocks',
            form.blocks.map((current, i) => (i === index ? block : current)),
        );

    const move = (index: number, by: number) => {
        const next = [...form.blocks];
        const target = index + by;
        if (target < 0 || target >= next.length) return;
        [next[index], next[target]] = [next[target], next[index]];
        set('blocks', next);
    };

    const save = (publish?: boolean) => {
        const payload = { ...form, is_published: publish ?? form.is_published };
        setSaving(true);
        router.put(update().url, payload, {
            preserveScroll: true,
            onSuccess: () => setErrors({}),
            onError: (bag) => setErrors(bag as Record<string, string>),
            onFinish: () => setSaving(false),
        });
    };

    // Built from the form, so unsaved edits show straight away. Only what needs the server (post
    // thumbnails) comes from the deferred payload.
    const previewPage = useMemo<BioPagePayload>(
        () => ({
            handle: form.handle,
            title: form.title || null,
            bio: form.bio || null,
            theme: form.theme,
            brand_name: publicPreview?.brand_name ?? null,
            blocks: form.blocks
                .filter((block) => block.is_visible)
                .map((block, index): BioBlockPayload | null => {
                    if (block.type === 'latest_posts') {
                        const posts =
                            publicPreview?.blocks.find(
                                (candidate) =>
                                    candidate.type === 'latest_posts',
                            )?.posts ?? [];

                        return posts.length === 0
                            ? null
                            : {
                                  id: index,
                                  type: 'latest_posts',
                                  posts: posts.slice(
                                      0,
                                      block.settings.limit ?? 3,
                                  ),
                              };
                    }

                    // Step GTM-2.1: the sign-up block previews as the real form, inert (the
                    // `isPreview` flag below), so the owner sees what a visitor will see.
                    if (block.type === 'email_capture') {
                        return {
                            id: index,
                            type: 'email_capture',
                            label: block.settings.label || 'Get my emails',
                            url: '',
                        };
                    }

                    const label =
                        block.settings.label ||
                        (block.type === 'media_kit'
                            ? 'My media kit'
                            : block.type === 'product'
                              ? products.find(
                                    (product) =>
                                        product.id ===
                                        block.settings.product_id,
                                )?.title
                              : block.settings.url);

                    return label ? { id: index, type: 'link', label } : null;
                })
                .filter((block): block is BioBlockPayload => block !== null),
        }),
        [form, publicPreview],
    );

    return (
        <Page>
            <Head title="Link in bio" />

            <ChapterHero
                eyebrow="One link, everything"
                title={
                    <>
                        Your <em>link in bio</em>.
                    </>
                }
                description="The one link you put in every profile: your best links, your latest posts and your media kit, on a page that looks like you."
                actions={
                    page?.is_published && (
                        <Button variant="on-ink" size="lg" asChild>
                            <a
                                href={page.public_url}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <Link2 className="size-4" />
                                View page
                            </a>
                        </Button>
                    )
                }
            />

            {!entitled && (
                <EmptyState
                    icon={Sparkles}
                    title="Publishing a bio page is on Creator Pro."
                    description="Build your page now and see exactly how it looks — upgrade when you want it live at your own handle."
                    action={
                        <Button variant="brand" asChild>
                            <Link href={editPlan()}>See plans</Link>
                        </Button>
                    }
                />
            )}

            <div className="grid gap-8 lg:grid-cols-[1.45fr_1fr]">
                <div className="flex flex-col gap-5">
                    <Panel eyebrow="Your page" title="Details">
                        <div className="flex flex-col gap-4">
                            <Field label="Handle" error={errors.handle}>
                                <div className="flex items-center gap-2">
                                    <span className="text-muted-foreground font-mono text-sm">
                                        /@
                                    </span>
                                    <Input
                                        value={form.handle}
                                        onChange={(event) =>
                                            set(
                                                'handle',
                                                event.target.value.toLowerCase(),
                                            )
                                        }
                                        maxLength={32}
                                        disabled={!canManage}
                                    />
                                </div>
                            </Field>

                            <Field label="Title" error={errors.title}>
                                <Input
                                    value={form.title}
                                    onChange={(event) =>
                                        set('title', event.target.value)
                                    }
                                    maxLength={120}
                                    placeholder="Your brand name"
                                    disabled={!canManage}
                                />
                            </Field>

                            <Field label="Bio" error={errors.bio}>
                                <Textarea
                                    value={form.bio}
                                    onChange={(event) =>
                                        set('bio', event.target.value)
                                    }
                                    rows={3}
                                    maxLength={500}
                                    placeholder="One or two lines about what you make."
                                    disabled={!canManage}
                                />
                            </Field>

                            <Field label="Theme" error={errors.theme}>
                                <Select
                                    value={form.theme}
                                    onValueChange={(value) =>
                                        set('theme', value)
                                    }
                                    disabled={!canManage}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {themes.map((theme) => (
                                            <SelectItem
                                                key={theme.value}
                                                value={theme.value}
                                            >
                                                {theme.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        </div>
                    </Panel>

                    {/* Step GTM-3.2: the creator's own hostname. The @handle address stays the
                        canonical one and keeps working whatever is set up here. */}
                    {page && (
                        <Panel
                            eyebrow="Your domain"
                            title="Use your own address"
                        >
                            {!domainsEntitled ? (
                                <p className="text-muted-foreground text-sm">
                                    Serving your page on your own domain is on
                                    Creator Pro.
                                </p>
                            ) : (
                                <>
                                    <p className="text-muted-foreground text-sm">
                                        Add a <code>CNAME</code> record pointing
                                        at <code>{domainTarget}</code>, then
                                        check it below. Your{' '}
                                        <code>@{form.handle}</code> address
                                        keeps working either way.
                                    </p>

                                    {domains.length > 0 && (
                                        <ul className="flex flex-col gap-3">
                                            {domains.map((domain) => (
                                                <li
                                                    key={domain.id}
                                                    className="border-border/70 flex flex-col gap-2 rounded-xl border p-3"
                                                >
                                                    <div className="flex flex-wrap items-center gap-3">
                                                        <span className="font-medium">
                                                            {domain.hostname}
                                                        </span>
                                                        <StatusPill
                                                            kind="bioDomain"
                                                            value={
                                                                domain.verified
                                                                    ? 'verified'
                                                                    : 'pending'
                                                            }
                                                        />
                                                        <span className="flex-1" />
                                                        {canManage && (
                                                            <>
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        router.post(
                                                                            verifyDomain(
                                                                                {
                                                                                    domain: domain.id,
                                                                                },
                                                                            )
                                                                                .url,
                                                                            {},
                                                                            {
                                                                                preserveScroll: true,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    Check now
                                                                </Button>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    aria-label={`Remove ${domain.hostname}`}
                                                                    onClick={() =>
                                                                        router.delete(
                                                                            destroyDomain(
                                                                                {
                                                                                    domain: domain.id,
                                                                                },
                                                                            )
                                                                                .url,
                                                                            {
                                                                                preserveScroll: true,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    <Trash2 className="size-4" />
                                                                </Button>
                                                            </>
                                                        )}
                                                    </div>
                                                    {!domain.verified && (
                                                        <p className="text-muted-foreground text-[13px]">
                                                            {domain.last_error ??
                                                                'Waiting on DNS.'}{' '}
                                                            An apex domain can
                                                            instead carry a TXT
                                                            record of{' '}
                                                            <code>
                                                                {
                                                                    domain.verification_token
                                                                }
                                                            </code>
                                                            .
                                                        </p>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    )}

                                    {canManage &&
                                        domains.length < maxDomains && (
                                            <div className="flex flex-wrap items-end gap-2">
                                                <Input
                                                    value={newDomain}
                                                    onChange={(event) =>
                                                        setNewDomain(
                                                            event.target.value,
                                                        )
                                                    }
                                                    placeholder="links.yourname.com"
                                                    className="w-full sm:w-80"
                                                />
                                                <Button
                                                    variant="outline"
                                                    disabled={
                                                        newDomain.trim() === ''
                                                    }
                                                    onClick={() =>
                                                        router.post(
                                                            storeDomain().url,
                                                            {
                                                                hostname:
                                                                    newDomain,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                                onSuccess: () =>
                                                                    setNewDomain(
                                                                        '',
                                                                    ),
                                                            },
                                                        )
                                                    }
                                                >
                                                    Add domain
                                                </Button>
                                            </div>
                                        )}
                                </>
                            )}
                        </Panel>
                    )}

                    <Panel
                        eyebrow="Blocks"
                        title="What people see"
                        actions={
                            canManage &&
                            blockTypes.map((type) => (
                                <Button
                                    key={type.value}
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        set('blocks', [
                                            ...form.blocks,
                                            {
                                                type: type.value,
                                                is_visible: true,
                                                settings: {},
                                            },
                                        ])
                                    }
                                >
                                    <Plus className="size-4" />
                                    {type.label}
                                </Button>
                            ))
                        }
                    >
                        {form.blocks.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                Nothing on your page yet. Add a link, your
                                latest posts or your media kit.
                            </p>
                        ) : (
                            <ul className="divide-border/70 divide-y">
                                {form.blocks.map((block, index) => (
                                    <li
                                        key={block.id ?? `new-${index}`}
                                        className="flex flex-col gap-3 py-4 first:pt-0"
                                    >
                                        <div className="flex items-center gap-1.5">
                                            <span className="text-muted-foreground font-mono text-xs">
                                                {String(index + 1).padStart(
                                                    2,
                                                    '0',
                                                )}
                                            </span>
                                            <span className="flex-1 truncate pl-1.5 text-sm font-semibold">
                                                {blockTypes.find(
                                                    (type) =>
                                                        type.value ===
                                                        block.type,
                                                )?.label ?? block.type}
                                            </span>
                                            {canManage && (
                                                <>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        aria-label="Move up"
                                                        onClick={() =>
                                                            move(index, -1)
                                                        }
                                                    >
                                                        <ArrowUp className="size-4" />
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        aria-label="Move down"
                                                        onClick={() =>
                                                            move(index, 1)
                                                        }
                                                    >
                                                        <ArrowDown className="size-4" />
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        aria-label={
                                                            block.is_visible
                                                                ? 'Hide block'
                                                                : 'Show block'
                                                        }
                                                        onClick={() =>
                                                            setBlock(index, {
                                                                ...block,
                                                                is_visible:
                                                                    !block.is_visible,
                                                            })
                                                        }
                                                    >
                                                        {block.is_visible ? (
                                                            <Eye className="size-4" />
                                                        ) : (
                                                            <EyeOff className="size-4" />
                                                        )}
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        aria-label="Remove block"
                                                        onClick={() =>
                                                            set(
                                                                'blocks',
                                                                form.blocks.filter(
                                                                    (_, i) =>
                                                                        i !==
                                                                        index,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                </>
                                            )}
                                        </div>

                                        {block.type !== 'latest_posts' && (
                                            <Input
                                                value={
                                                    block.settings.label ?? ''
                                                }
                                                onChange={(event) =>
                                                    setBlock(index, {
                                                        ...block,
                                                        settings: {
                                                            ...block.settings,
                                                            label: event.target
                                                                .value,
                                                        },
                                                    })
                                                }
                                                maxLength={60}
                                                placeholder="Button label"
                                                disabled={!canManage}
                                            />
                                        )}

                                        {block.type === 'product' && (
                                            <Select
                                                value={
                                                    block.settings.product_id?.toString() ??
                                                    ''
                                                }
                                                onValueChange={(value) =>
                                                    setBlock(index, {
                                                        ...block,
                                                        settings: {
                                                            ...block.settings,
                                                            product_id:
                                                                Number(value),
                                                        },
                                                    })
                                                }
                                                disabled={
                                                    !canManage ||
                                                    products.length === 0
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue
                                                        placeholder={
                                                            products.length ===
                                                            0
                                                                ? 'Nothing on sale yet'
                                                                : 'Pick a product'
                                                        }
                                                    />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {products.map((product) => (
                                                        <SelectItem
                                                            key={product.id}
                                                            value={product.id.toString()}
                                                        >
                                                            {product.title}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        )}

                                        {block.type === 'link' && (
                                            <div className="flex flex-col gap-1">
                                                <Input
                                                    value={
                                                        block.settings.url ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        setBlock(index, {
                                                            ...block,
                                                            settings: {
                                                                ...block.settings,
                                                                url: event
                                                                    .target
                                                                    .value,
                                                            },
                                                        })
                                                    }
                                                    type="url"
                                                    placeholder="https://"
                                                    disabled={!canManage}
                                                />
                                                {errors[
                                                    `blocks.${index}.settings.url`
                                                ] && (
                                                    <p className="text-destructive text-sm">
                                                        {
                                                            errors[
                                                                `blocks.${index}.settings.url`
                                                            ]
                                                        }
                                                    </p>
                                                )}
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    {canManage && (
                        <div className="flex flex-wrap items-center gap-3">
                            <Button
                                type="button"
                                disabled={saving}
                                onClick={() => save()}
                            >
                                Save
                            </Button>
                            {form.is_published ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    disabled={saving}
                                    onClick={() => save(false)}
                                >
                                    <EyeOff className="size-4" />
                                    Take offline
                                </Button>
                            ) : (
                                <Button
                                    type="button"
                                    variant="brand"
                                    disabled={saving}
                                    onClick={() => save(true)}
                                >
                                    <Eye className="size-4" />
                                    Publish
                                </Button>
                            )}
                        </div>
                    )}
                </div>

                <Panel eyebrow="Preview" title="On a phone">
                    <div className="border-ink/90 mx-auto w-full max-w-[320px] overflow-hidden rounded-[2.25rem] border-8 shadow-md">
                        <BioPageView page={previewPage} isPreview />
                    </div>
                </Panel>
            </div>
        </Page>
    );
}

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <Label>{label}</Label>
            {children}
            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}

BusinessBioPage.layout = {
    breadcrumbs: [{ title: 'Link in bio', href: editBio() }],
};
