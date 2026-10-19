import {
    Bookmark,
    Camera,
    Globe,
    Heart,
    MessageCircle,
    MessageSquare,
    MoreHorizontal,
    Music2,
    Plus,
    Repeat2,
    Search,
    Send,
    Share2,
    ThumbsUp,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import type { PreviewAsset } from '@/components/asset-preview-carousel';
import { platformIcon } from '@/components/platform-icon';
import { FoldedCaption } from '@/components/social-preview/caption';
import {
    CarouselArrows,
    CarouselDots,
    cropFor,
    FramedMedia,
    isVideoAsset,
    MediaGrid,
    publishableMedia,
    SafeZones,
    useMediaAspects,
} from '@/components/social-preview/media';
import type { Crop, MediaFormat, MediaFrame } from '@/lib/media-format';
import { describeRatio, frameFor, publishedCaption } from '@/lib/media-format';
import { cn } from '@/lib/utils';

export type PreviewTarget = {
    id: number | string;
    platform: string;
    handle: string;
    /** The page/profile name Facebook and LinkedIn lead with; falls back to the handle. */
    display_name?: string | null;
};

const platformLabel: Record<string, string> = {
    instagram: 'Instagram',
    tiktok: 'TikTok',
    facebook: 'Facebook',
    linkedin: 'LinkedIn',
};

type LayoutProps = {
    handle: string;
    name: string;
    caption: string;
    media: PreviewAsset[];
    frame: MediaFrame;
    crops: Record<number, Crop> | undefined;
    aspects: ReturnType<typeof useMediaAspects>;
    musicName?: string;
    locationName?: string;
    showSafeZones: boolean;
};

function Avatar({ label, className }: { label: string; className?: string }) {
    return (
        <span
            aria-hidden
            className={cn(
                'bg-primary text-primary-foreground flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                className,
            )}
        >
            {label.replace(/^@/, '').slice(0, 1).toUpperCase() || '?'}
        </span>
    );
}

/** A swipeable run of framed slides (Instagram feed, TikTok photo mode). */
function Slides({
    media,
    frame,
    crops,
    aspects,
    index,
    fill = false,
}: {
    media: PreviewAsset[];
    frame: MediaFrame;
    crops: Record<number, Crop> | undefined;
    aspects: ReturnType<typeof useMediaAspects>;
    index: number;
    fill?: boolean;
}) {
    const active = media[Math.min(index, Math.max(media.length - 1, 0))];

    return (
        <FramedMedia
            key={active?.id ?? 'empty'}
            asset={active}
            frame={frame}
            crop={active ? cropFor(active, crops) : { x: 0.5, y: 0.5, zoom: 1 }}
            aspect={aspects.aspectOf(active)}
            onMeasure={aspects.measure}
            fill={fill}
        />
    );
}

function InstagramFeed({
    handle,
    caption,
    media,
    frame,
    crops,
    aspects,
    musicName,
    locationName,
}: LayoutProps) {
    const [index, setIndex] = useState(0);
    const subline = musicName ? `♫ ${musicName}` : locationName;

    return (
        <div className="border-border bg-card text-foreground w-full overflow-hidden rounded-xl border text-[13px]">
            <div className="flex items-center gap-2.5 px-3 py-2.5">
                <span className="ring-border rounded-full p-0.5 ring-2">
                    <Avatar label={handle} className="size-7" />
                </span>
                <div className="grid min-w-0 flex-1 leading-tight">
                    <span className="truncate font-semibold">
                        {handle || 'your_handle'}
                    </span>
                    {subline && (
                        <span className="text-muted-foreground truncate text-[11px]">
                            {subline}
                        </span>
                    )}
                </div>
                <MoreHorizontal className="size-4" />
            </div>

            <div className="relative">
                <Slides
                    media={media}
                    frame={frame}
                    crops={crops}
                    aspects={aspects}
                    index={index}
                />
                {media.length > 1 && (
                    <span className="bg-ink/70 text-cream absolute top-2.5 right-2.5 rounded-full px-2 py-0.5 font-mono text-[10px] tabular-nums">
                        {index + 1}/{media.length}
                    </span>
                )}
                <CarouselArrows
                    index={index}
                    count={media.length}
                    onChange={setIndex}
                />
            </div>

            <div className="relative flex items-center justify-between px-3 pt-2.5">
                <div className="flex items-center gap-3.5">
                    <Heart className="size-5" />
                    <MessageCircle className="size-5" />
                    <Send className="size-5" />
                </div>
                <CarouselDots
                    index={index}
                    count={media.length}
                    onChange={setIndex}
                    className="absolute left-1/2 -translate-x-1/2"
                />
                <Bookmark className="size-5" />
            </div>

            <div className="grid gap-1 px-3 pt-2 pb-3">
                <FoldedCaption
                    text={caption}
                    platform="instagram"
                    lead={
                        <span className="me-1 font-semibold">
                            {handle || 'your_handle'}
                        </span>
                    }
                />
                <span className="text-muted-foreground text-[11px]">
                    Just now
                </span>
            </div>
        </div>
    );
}

/** Full-screen vertical chrome shared by TikTok and Instagram Reels. */
function VerticalScreen({
    children,
    showSafeZones,
    platform,
}: {
    children: ReactNode;
    showSafeZones: boolean;
    platform: string;
}) {
    return (
        <div className="border-border bg-ink text-cream relative mx-auto aspect-[9/16] w-full max-w-[320px] overflow-hidden rounded-2xl border text-[13px] shadow-md">
            {children}
            {showSafeZones && <SafeZones platform={platform} />}
        </div>
    );
}

function RailAction({
    icon: Icon,
    label,
}: {
    icon: typeof Heart;
    label?: string;
}) {
    return (
        <span className="flex flex-col items-center gap-0.5">
            <Icon className="size-6 shrink-0 drop-shadow" />
            {label && (
                <span className="text-[10px] font-medium drop-shadow">
                    {label}
                </span>
            )}
        </span>
    );
}

function InstagramReel({
    handle,
    caption,
    media,
    frame,
    crops,
    aspects,
    musicName,
    showSafeZones,
}: LayoutProps) {
    return (
        <VerticalScreen showSafeZones={showSafeZones} platform="instagram">
            <Slides
                media={media}
                frame={frame}
                crops={crops}
                aspects={aspects}
                index={0}
                fill
            />
            <div className="from-ink/80 to-ink/30 absolute inset-0 bg-gradient-to-t via-transparent" />

            <div className="absolute inset-x-0 top-0 flex items-center justify-between p-3 font-semibold">
                <span className="text-base">Reels</span>
                <Camera className="size-5" />
            </div>

            <div className="absolute right-2.5 bottom-20 flex flex-col items-center gap-4">
                <RailAction icon={Heart} label="Like" />
                <RailAction icon={MessageCircle} label="Comment" />
                <RailAction icon={Send} label="Share" />
                <RailAction icon={MoreHorizontal} />
                <span className="border-cream bg-muted size-6 rounded-md border-2" />
            </div>

            <div className="absolute right-14 bottom-3 left-3 grid gap-2">
                <span className="flex items-center gap-2">
                    <Avatar label={handle} className="size-7" />
                    <span className="truncate font-semibold">
                        {handle || 'your_handle'}
                    </span>
                    <span className="border-cream/80 rounded-md border px-2 py-0.5 text-[11px] font-semibold">
                        Follow
                    </span>
                </span>
                <FoldedCaption
                    text={caption}
                    platform="instagram"
                    onMedia
                    className="text-[12px]"
                />
                <span className="text-cream/85 flex items-center gap-1.5 truncate text-[11px]">
                    <Music2 className="size-3.5 shrink-0" />
                    <span className="truncate">
                        {musicName ||
                            `${handle || 'your_handle'} · Original audio`}
                    </span>
                </span>
            </div>
        </VerticalScreen>
    );
}

function TikTokPost({
    handle,
    caption,
    media,
    frame,
    crops,
    aspects,
    musicName,
    showSafeZones,
}: LayoutProps) {
    const [index, setIndex] = useState(0);
    const photoMode = media.length > 0 && !isVideoAsset(media[0]);

    return (
        <VerticalScreen showSafeZones={showSafeZones} platform="tiktok">
            <Slides
                media={media}
                frame={frame}
                crops={crops}
                aspects={aspects}
                index={index}
                fill
            />
            <div className="from-ink/85 to-ink/30 absolute inset-0 bg-gradient-to-t via-transparent" />

            <div className="absolute inset-x-0 top-0 flex items-center justify-center gap-5 p-3 font-semibold">
                <span className="text-cream/65">Following</span>
                <span className="border-cream border-b-2 pb-0.5">For You</span>
                <Search className="absolute right-3 size-5" />
            </div>

            {photoMode && (
                <CarouselArrows
                    index={index}
                    count={media.length}
                    onChange={setIndex}
                />
            )}

            <div className="absolute right-2 bottom-16 flex flex-col items-center gap-4">
                <span className="relative mb-1">
                    <Avatar
                        label={handle}
                        className="border-cream size-10 border-2"
                    />
                    <span className="bg-cream text-ink absolute -bottom-1.5 left-1/2 flex size-4 -translate-x-1/2 items-center justify-center rounded-full">
                        <Plus className="size-3" />
                    </span>
                </span>
                <RailAction icon={Heart} label="Like" />
                <RailAction icon={MessageCircle} label="Comment" />
                <RailAction icon={Bookmark} label="Save" />
                <RailAction icon={Share2} label="Share" />
                <span className="border-cream/50 bg-ink flex size-8 items-center justify-center rounded-full border-[5px]">
                    <Music2 className="size-3" />
                </span>
            </div>

            <div className="absolute right-16 bottom-3 left-3 grid gap-1.5">
                {photoMode && (
                    <CarouselDots
                        index={index}
                        count={media.length}
                        onChange={setIndex}
                        tone="light"
                    />
                )}
                <span className="font-semibold">
                    {handle ? `@${handle.replace(/^@/, '')}` : '@your_handle'}
                </span>
                <FoldedCaption
                    text={caption}
                    platform="tiktok"
                    onMedia
                    className="text-[12px]"
                />
                <span className="text-cream/85 flex items-center gap-1.5 truncate text-[11px]">
                    <Music2 className="size-3.5 shrink-0" />
                    <span className="truncate">
                        {musicName || 'Original sound'}
                    </span>
                </span>
            </div>
        </VerticalScreen>
    );
}

function FeedMedia({
    media,
    frame,
    crops,
    aspects,
}: Pick<LayoutProps, 'media' | 'frame' | 'crops' | 'aspects'>) {
    if (media.length === 0) {
        return null;
    }

    if (media.length > 1) {
        return (
            <MediaGrid
                media={media}
                frame={frame}
                crops={crops}
                aspectOf={aspects.aspectOf}
                onMeasure={aspects.measure}
            />
        );
    }

    return (
        <Slides
            media={media}
            frame={frame}
            crops={crops}
            aspects={aspects}
            index={0}
        />
    );
}

function FacebookPost({
    name,
    caption,
    media,
    frame,
    crops,
    aspects,
    locationName,
}: LayoutProps) {
    return (
        <div className="border-border bg-card text-foreground w-full overflow-hidden rounded-xl border text-[13px]">
            <div className="flex items-start gap-2.5 px-3 pt-3 pb-2">
                <Avatar label={name} className="size-9" />
                <div className="grid min-w-0 flex-1 leading-tight">
                    <span className="truncate font-semibold">
                        {name || 'Your page'}
                        {locationName && (
                            <span className="text-muted-foreground font-normal">
                                {' '}
                                is at{' '}
                                <span className="text-foreground font-semibold">
                                    {locationName}
                                </span>
                            </span>
                        )}
                    </span>
                    <span className="text-muted-foreground flex items-center gap-1 text-[11px]">
                        Just now · <Globe className="size-3" />
                    </span>
                </div>
                <MoreHorizontal className="text-muted-foreground size-4" />
                <X className="text-muted-foreground size-4" />
            </div>

            <FoldedCaption
                text={caption}
                platform="facebook"
                className="px-3 pb-2.5"
            />

            <FeedMedia
                media={media}
                frame={frame}
                crops={crops}
                aspects={aspects}
            />

            <div className="border-border/70 text-muted-foreground mx-3 mt-1 flex items-center justify-around border-t py-1.5 text-xs font-medium">
                <span className="flex items-center gap-1.5 py-1">
                    <ThumbsUp className="size-4" /> Like
                </span>
                <span className="flex items-center gap-1.5 py-1">
                    <MessageCircle className="size-4" /> Comment
                </span>
                <span className="flex items-center gap-1.5 py-1">
                    <Share2 className="size-4" /> Share
                </span>
            </div>
        </div>
    );
}

function LinkedInPost({
    name,
    handle,
    caption,
    media,
    frame,
    crops,
    aspects,
}: LayoutProps) {
    return (
        <div className="border-border bg-card text-foreground w-full overflow-hidden rounded-xl border text-[13px]">
            <div className="flex items-start gap-2.5 px-3 pt-3 pb-2">
                <Avatar label={name} className="size-10 rounded-md" />
                <div className="grid min-w-0 flex-1 leading-tight">
                    <span className="truncate font-semibold">
                        {name || 'Your company'}
                    </span>
                    {handle && name !== handle && (
                        <span className="text-muted-foreground truncate text-[11px]">
                            {handle}
                        </span>
                    )}
                    <span className="text-muted-foreground flex items-center gap-1 text-[11px]">
                        Now · <Globe className="size-3" />
                    </span>
                </div>
                <span className="text-info flex items-center gap-0.5 text-xs font-semibold">
                    <Plus className="size-3.5" /> Follow
                </span>
            </div>

            <FoldedCaption
                text={caption}
                platform="linkedin"
                className="px-3 pb-2.5"
            />

            <FeedMedia
                media={media}
                frame={frame}
                crops={crops}
                aspects={aspects}
            />

            <div className="border-border/70 text-muted-foreground mx-3 mt-1 grid grid-cols-4 border-t py-1.5 text-[11px] font-medium">
                {[
                    { icon: ThumbsUp, label: 'Like' },
                    { icon: MessageSquare, label: 'Comment' },
                    { icon: Repeat2, label: 'Repost' },
                    { icon: Send, label: 'Send' },
                ].map(({ icon: Icon, label }) => (
                    <span
                        key={label}
                        className="flex flex-col items-center gap-0.5 py-1"
                    >
                        <Icon className="size-4" />
                        {label}
                    </span>
                ))}
            </div>
        </div>
    );
}

/** What the preview is showing, e.g. "Feed · 4:5", "Reel", "Photo mode · 3 slides". */
function describePlacement(
    platform: string,
    media: PreviewAsset[],
    frame: MediaFrame,
): string {
    const video = media.length > 0 && isVideoAsset(media[0]);

    if (platform === 'tiktok') {
        if (media.length === 0) {
            return 'Needs media';
        }

        return video
            ? 'Video'
            : `Photo mode${media.length > 1 ? ` · ${media.length} slides` : ''}`;
    }

    if (platform === 'instagram' && video) {
        return 'Reel';
    }

    if (media.length === 0) {
        return 'Text post';
    }

    if (media.length > 1) {
        return platform === 'instagram'
            ? `Carousel · ${describeRatio(frame.ratio)}`
            : `${media.length} images`;
    }

    return `${video ? 'Video' : 'Image'} · ${describeRatio(frame.ratio)}`;
}

/**
 * Live, Planable-style preview of how a post renders on one channel. Purely presentational —
 * driven by the editor's own state (caption, hashtags, media, format, framing), so it updates as
 * the user types with no network round-trip. Media is framed by the same rules the publisher
 * crops with (`lib/media-format.ts`), and the caption is the exact text that publishes.
 */
export function SocialPostPreview({
    target,
    caption,
    hashtags,
    assets,
    musicName,
    locationName,
    mediaFormat,
    crops,
    showSafeZones = false,
    className,
}: {
    target: PreviewTarget;
    caption: string;
    hashtags: string[];
    assets: PreviewAsset[];
    musicName?: string;
    locationName?: string;
    /** Format per channel; the saved `media_format` or the editor's unsaved choice. */
    mediaFormat?: MediaFormat;
    /** Framing per asset id, overriding each asset's saved `crop` (unsaved editor edits). */
    crops?: Record<number, Crop>;
    /** Outline where TikTok/Reels chrome covers full-screen media. */
    showSafeZones?: boolean;
    className?: string;
}) {
    const platform = target.platform;
    const aspects = useMediaAspects();
    const media = publishableMedia(assets);
    const first = media[0];
    const frame = frameFor(
        platform,
        mediaFormat?.[platform],
        aspects.aspectOf(first),
        first ? isVideoAsset(first) : false,
    );

    const props: LayoutProps = {
        handle: target.handle,
        name: target.display_name || target.handle,
        caption: publishedCaption(caption, hashtags),
        media,
        frame,
        crops,
        aspects,
        musicName,
        locationName,
        showSafeZones,
    };

    const Icon = platformIcon(platform);
    const isReel = platform === 'instagram' && first && isVideoAsset(first);

    return (
        <div
            className={cn(
                'flex w-full flex-col items-center gap-2.5',
                className,
            )}
        >
            <span className="eyebrow text-muted-foreground inline-flex items-center gap-1.5">
                <Icon className="size-3.5" />
                {platformLabel[platform] ?? platform}
                <span aria-hidden>·</span>
                <span>{describePlacement(platform, media, frame)}</span>
            </span>
            {platform === 'tiktok' ? (
                <TikTokPost {...props} />
            ) : isReel ? (
                <InstagramReel {...props} />
            ) : platform === 'instagram' ? (
                <InstagramFeed {...props} />
            ) : platform === 'linkedin' ? (
                <LinkedInPost {...props} />
            ) : (
                <FacebookPost {...props} />
            )}
        </div>
    );
}
