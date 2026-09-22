import { Bookmark, ChevronLeft, ChevronRight, Facebook, Heart, Instagram, Linkedin, MessageCircle, Music2, Send, Share2, ThumbsUp } from 'lucide-react';
import { useMemo, useState } from 'react';
import { cn } from '@/lib/utils';
import type { PreviewAsset } from '@/components/asset-preview-carousel';

export type PreviewTarget = {
    id: number | string;
    platform: string;
    handle: string;
};

const platformIcon: Record<string, typeof Instagram> = {
    instagram: Instagram,
    tiktok: Music2,
    facebook: Facebook,
    linkedin: Linkedin,
};

const platformLabel: Record<string, string> = {
    instagram: 'Instagram',
    tiktok: 'TikTok',
    facebook: 'Facebook',
    linkedin: 'LinkedIn',
};

function isVideoAsset(asset: PreviewAsset): boolean {
    if (asset.type) {
        return asset.type.startsWith('video');
    }

    return /\.(mp4|mov|webm|m4v)$/i.test(asset.url ?? '');
}

function PreviewMedia({ asset, className }: { asset: PreviewAsset | undefined; className?: string }) {
    if (!asset || !asset.url) {
        return (
            <div className={cn('flex items-center justify-center bg-muted text-xs text-muted-foreground', className)}>
                No media
            </div>
        );
    }

    if (isVideoAsset(asset)) {
        return <video src={asset.url} className={cn('h-full w-full object-cover', className)} muted playsInline loop autoPlay />;
    }

    return <img src={asset.url} alt="" className={cn('h-full w-full object-cover', className)} />;
}

function CaptionWithHashtags({ caption, hashtags }: { caption: string; hashtags: string[] }) {
    if (!caption && hashtags.length === 0) {
        return <span className="text-muted-foreground italic">Your caption will appear here…</span>;
    }

    return (
        <>
            {caption && <span className="whitespace-pre-wrap">{caption}</span>}
            {hashtags.length > 0 && (
                <span className="text-primary">
                    {caption ? ' ' : ''}
                    {hashtags.map((tag) => `#${tag}`).join(' ')}
                </span>
            )}
        </>
    );
}

function InstagramPreview({
    handle,
    caption,
    hashtags,
    assets,
    locationName,
}: {
    handle: string;
    caption: string;
    hashtags: string[];
    assets: PreviewAsset[];
    locationName?: string;
}) {
    const [assetIndex, setAssetIndex] = useState(0);
    const active = assets[Math.min(assetIndex, Math.max(assets.length - 1, 0))];

    return (
        <div className="w-full overflow-hidden rounded-lg border border-border bg-card text-sm">
            <div className="flex items-center gap-2 p-2.5">
                <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-gradient-brand text-xs font-semibold text-primary-foreground">
                    {handle.slice(0, 1).toUpperCase() || '?'}
                </span>
                <div className="grid min-w-0 flex-1 leading-tight">
                    <span className="truncate font-semibold">{handle || 'your_handle'}</span>
                    {locationName && <span className="truncate text-xs text-muted-foreground">{locationName}</span>}
                </div>
            </div>

            <div className="relative aspect-square w-full bg-muted">
                <PreviewMedia asset={active} />
                {assets.length > 1 && (
                    <>
                        <button
                            type="button"
                            aria-label="Previous media"
                            onClick={() => setAssetIndex((i) => (i - 1 + assets.length) % assets.length)}
                            className="absolute top-1/2 left-1.5 flex size-6 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-white transition-colors hover:bg-black/60"
                        >
                            <ChevronLeft className="size-4" />
                        </button>
                        <button
                            type="button"
                            aria-label="Next media"
                            onClick={() => setAssetIndex((i) => (i + 1) % assets.length)}
                            className="absolute top-1/2 right-1.5 flex size-6 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-white transition-colors hover:bg-black/60"
                        >
                            <ChevronRight className="size-4" />
                        </button>
                        <div className="absolute bottom-2 left-1/2 flex -translate-x-1/2 gap-1">
                            {assets.map((_, i) => (
                                <button
                                    key={i}
                                    type="button"
                                    onClick={() => setAssetIndex(i)}
                                    className={cn(
                                        'size-1.5 rounded-full transition-colors',
                                        i === assetIndex ? 'bg-white' : 'bg-white/50',
                                    )}
                                />
                            ))}
                        </div>
                    </>
                )}
            </div>

            <div className="flex items-center justify-between px-2.5 pt-2">
                <div className="flex items-center gap-3">
                    <Heart className="size-5" />
                    <MessageCircle className="size-5" />
                    <Send className="size-5" />
                </div>
                <Bookmark className="size-5" />
            </div>

            <div className="px-2.5 py-2 leading-snug">
                <span className="mr-1 font-semibold">{handle || 'your_handle'}</span>
                <CaptionWithHashtags caption={caption} hashtags={hashtags} />
            </div>
        </div>
    );
}

function TikTokPreview({
    handle,
    caption,
    hashtags,
    assets,
    musicName,
}: {
    handle: string;
    caption: string;
    hashtags: string[];
    assets: PreviewAsset[];
    musicName?: string;
}) {
    return (
        <div className="relative mx-auto aspect-[9/16] w-full max-w-[340px] overflow-hidden rounded-2xl border border-border bg-black text-white shadow-lg">
            <div className="absolute inset-0">
                <PreviewMedia asset={assets[0]} />
            </div>
            <div className="absolute inset-0 bg-gradient-to-t from-black/85 via-black/10 to-black/30" />

            <div className="absolute inset-x-0 top-0 flex items-center justify-center gap-6 p-3 text-sm font-semibold text-white/70">
                <span>Following</span>
                <span className="text-white">For You</span>
            </div>

            <div className="absolute right-2.5 bottom-16 flex flex-col items-center gap-5 text-white">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-full border-2 border-white bg-muted text-[11px] font-semibold text-foreground">
                    {handle.slice(0, 1).toUpperCase() || '?'}
                </span>
                <div className="flex flex-col items-center gap-0.5">
                    <Heart className="size-7 shrink-0" />
                    <span className="text-[11px] font-medium">24.5K</span>
                </div>
                <div className="flex flex-col items-center gap-0.5">
                    <MessageCircle className="size-7 shrink-0" />
                    <span className="text-[11px] font-medium">312</span>
                </div>
                <div className="flex flex-col items-center gap-0.5">
                    <Bookmark className="size-7 shrink-0" />
                    <span className="text-[11px] font-medium">1.2K</span>
                </div>
                <div className="flex flex-col items-center gap-0.5">
                    <Share2 className="size-7 shrink-0" />
                    <span className="text-[11px] font-medium">Share</span>
                </div>
                <span className="mt-1 flex size-7 shrink-0 items-center justify-center rounded-full border-2 border-white/60 bg-black">
                    <Music2 className="size-3.5" />
                </span>
            </div>

            <div className="absolute bottom-4 left-3 right-16 grid gap-1.5 text-sm">
                <span className="font-semibold">@{handle || 'your_handle'}</span>
                <p className="line-clamp-3 leading-snug">
                    <CaptionWithHashtags caption={caption} hashtags={hashtags} />
                </p>
                <span className="inline-flex items-center gap-1.5 truncate text-xs text-white/80">
                    <Music2 className="size-3.5 shrink-0" />
                    <span className="truncate">{musicName || 'Original sound'}</span>
                </span>
            </div>
        </div>
    );
}

function FeedStylePreview({
    handle,
    caption,
    hashtags,
    assets,
    platform,
}: {
    handle: string;
    caption: string;
    hashtags: string[];
    assets: PreviewAsset[];
    platform: string;
}) {
    const Icon = platformIcon[platform] ?? Share2;

    return (
        <div className="w-full overflow-hidden rounded-lg border border-border bg-card text-sm">
            <div className="flex items-center gap-2 p-2.5">
                <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted">
                    <Icon className="size-4 text-primary" />
                </span>
                <div className="grid min-w-0 flex-1 leading-tight">
                    <span className="truncate font-semibold">{handle || 'your_handle'}</span>
                    <span className="text-xs text-muted-foreground">Just now</span>
                </div>
            </div>

            <div className="px-2.5 pb-2 leading-snug">
                <CaptionWithHashtags caption={caption} hashtags={hashtags} />
            </div>

            {assets.length > 0 && assets[0]?.url && (
                <div className="aspect-video w-full bg-muted">
                    <PreviewMedia asset={assets[0]} />
                </div>
            )}

            <div className="flex items-center gap-4 px-2.5 py-2 text-muted-foreground">
                <span className="flex items-center gap-1.5 text-xs">
                    <ThumbsUp className="size-4" /> Like
                </span>
                <span className="flex items-center gap-1.5 text-xs">
                    <MessageCircle className="size-4" /> Comment
                </span>
                <span className="flex items-center gap-1.5 text-xs">
                    <Share2 className="size-4" /> Share
                </span>
            </div>
        </div>
    );
}

/**
 * Live, Planable-style visual preview of how a post will render on a given social platform.
 * Purely presentational — driven by the same state the editor form already manages (caption,
 * hashtags, selected media, music/location), so it updates as the user types/selects, with no
 * network round-trip.
 */
export function SocialPostPreview({
    target,
    caption,
    hashtags,
    assets,
    musicName,
    locationName,
    className,
}: {
    target: PreviewTarget;
    caption: string;
    hashtags: string[];
    assets: PreviewAsset[];
    musicName?: string;
    locationName?: string;
    className?: string;
}) {
    const platform = target.platform;

    const body = useMemo(() => {
        switch (platform) {
            case 'instagram':
                return (
                    <InstagramPreview
                        handle={target.handle}
                        caption={caption}
                        hashtags={hashtags}
                        assets={assets}
                        locationName={locationName}
                    />
                );
            case 'tiktok':
                return (
                    <TikTokPreview
                        handle={target.handle}
                        caption={caption}
                        hashtags={hashtags}
                        assets={assets}
                        musicName={musicName}
                    />
                );
            default:
                return (
                    <FeedStylePreview
                        handle={target.handle}
                        caption={caption}
                        hashtags={hashtags}
                        assets={assets}
                        platform={platform}
                    />
                );
        }
    }, [platform, target.handle, caption, hashtags, assets, musicName, locationName]);

    return (
        <div className={cn('flex w-full flex-col items-center gap-2', className)}>
            <span className="inline-flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {(() => {
                    const Icon = platformIcon[platform] ?? Share2;
                    return <Icon className="size-3.5" />;
                })()}
                {platformLabel[platform] ?? platform}
            </span>
            {body}
        </div>
    );
}
