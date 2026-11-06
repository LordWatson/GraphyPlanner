import { ArrowUpRight } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Step CG-3.1 — the link in bio as a visitor sees it. One component for the public page and the
 * owner's live preview in the editor, so what they approve is what ships.
 *
 * Themes are presets, never raw colour: each is an approved brand pairing (brand guidelines §3.3 —
 * Ink on Cream/Lime/Lilac/Blue, Cream on Ink), so a page can't be made unreadable.
 */
export const BIO_THEME_CLASSES: Record<string, string> = {
    cream: 'bg-cream text-ink',
    ink: 'bg-ink text-cream',
    lime: 'bg-lime text-ink',
    electric: 'bg-electric text-ink',
    lilac: 'bg-lilac text-ink',
};

export type BioBlockPayload = {
    id: number;
    type: 'link' | 'latest_posts' | 'media_kit' | 'product';
    label?: string;
    url?: string;
    posts?: {
        caption: string | null;
        thumbnail_url: string | null;
        url: string | null;
    }[];
};

export type BioPagePayload = {
    handle: string;
    title: string | null;
    bio: string | null;
    theme: string;
    brand_name: string | null;
    blocks: BioBlockPayload[];
};

export function BioPageView({
    page,
    className,
}: {
    page: BioPagePayload;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-col items-center gap-6 px-6 py-12',
                BIO_THEME_CLASSES[page.theme] ?? BIO_THEME_CLASSES.cream,
                className,
            )}
        >
            <header className="flex flex-col items-center gap-2 text-center">
                <h1 className="font-display text-3xl leading-tight text-balance">
                    {page.title ?? page.brand_name}
                </h1>
                <p className="font-mono text-xs tracking-wide uppercase opacity-70">
                    @{page.handle}
                </p>
                {page.bio && (
                    <p className="max-w-sm text-[15px] opacity-90">
                        {page.bio}
                    </p>
                )}
            </header>

            <div className="flex w-full max-w-sm flex-col gap-3">
                {page.blocks.map((block) =>
                    block.type === 'latest_posts' ? (
                        <LatestPosts
                            key="latest_posts"
                            posts={block.posts ?? []}
                        />
                    ) : (
                        <a
                            key={block.id}
                            href={block.url}
                            className="flex items-center justify-between gap-3 rounded-full border-2 border-current/25 px-5 py-3.5 text-[15px] font-semibold transition hover:border-current/60"
                        >
                            <span className="truncate">{block.label}</span>
                            <ArrowUpRight className="size-4 shrink-0 opacity-60" />
                        </a>
                    ),
                )}
            </div>
        </div>
    );
}

function LatestPosts({
    posts,
}: {
    posts: NonNullable<BioBlockPayload['posts']>;
}) {
    return (
        <div className="grid grid-cols-3 gap-2">
            {posts.map((post, index) => (
                <a
                    key={index}
                    href={post.url ?? undefined}
                    className="aspect-square overflow-hidden rounded-xl border border-current/15 bg-current/5"
                    aria-label={post.caption ?? 'Latest post'}
                >
                    {post.thumbnail_url && (
                        <img
                            src={post.thumbnail_url}
                            alt={post.caption ?? ''}
                            className="size-full object-cover"
                            loading="lazy"
                        />
                    )}
                </a>
            ))}
        </div>
    );
}
