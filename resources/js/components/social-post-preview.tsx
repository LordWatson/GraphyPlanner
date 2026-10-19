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
import type { PlatformFormat } from '@/lib/platforms';
import {
    capabilityOf,
    platformLabelOf,
    resolveFormat,
    usePlatforms,
} from '@/lib/platforms';
import { cn } from '@/lib/utils';

export type PreviewTarget = {
    id: number | string;
    platform: string;
    handle: string;
    /** The page/profile name Facebook and LinkedIn lead with; falls back to the handle. */
    display_name?: string | null;
    /** Step M-2.2: this channel's own caption / hashtags / link / first comment (null = master). */
    caption?: string | null;
    hashtags?: string[] | null;
    link?: string | null;
    firstComment?: string | null;
    /** Step M-2.3: the channel's explicit format (null = Auto, from the media). */
    format?: string | null;
    /** The caption exactly as the server will publish it (review link, portal). */
    publishedCaption?: string | null;
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
    firstComment?: string | null;
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
    firstComment,
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
            <FirstComment handle={handle} text={firstComment} />
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
    handle,
    caption,
    media,
    frame,
    crops,
    aspects,
    locationName,
    firstComment,
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
            <FirstComment handle={name || handle} text={firstComment} />
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
    firstComment,
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
            <FirstComment handle={name} text={firstComment} />
        </div>
    );
}

/** The first comment, as it appears under the post (Step M-2.2). */
function FirstComment({
    handle,
    text,
}: {
    handle: string;
    text?: string | null;
}) {
    if (!text) {
        return null;
    }

    return (
        <div className="border-border/70 flex items-start gap-2 border-t px-3 py-2.5 text-[12px]">
            <Avatar label={handle} className="size-6 text-[10px]" />
            <p
                className="min-w-0 leading-snug break-words whitespace-pre-wrap"
                dir="auto"
            >
                <span className="me-1 font-semibold">
                    {handle || 'your_handle'}
                </span>
                {text}
                <span className="text-muted-foreground mt-0.5 block font-mono text-[10px] uppercase">
                    First comment
                </span>
            </p>
        </div>
    );
}

/** Instagram / Facebook Stories: full screen, progress bar, no caption on the media. */
function StoryScreen({
    handle,
    media,
    frame,
    crops,
    aspects,
    showSafeZones,
    platform,
}: LayoutProps & { platform: string }) {
    return (
        <VerticalScreen showSafeZones={showSafeZones} platform={platform}>
            <Slides
                media={media}
                frame={frame}
                crops={crops}
                aspects={aspects}
                index={0}
                fill
            />
            <div className="from-ink/60 absolute inset-x-0 top-0 h-24 bg-gradient-to-b to-transparent" />
            <div className="absolute inset-x-3 top-2.5 grid gap-2">
                <span className="bg-cream/40 h-0.5 overflow-hidden rounded-full">
                    <span className="bg-cream block h-full w-1/3" />
                </span>
                <span className="flex items-center gap-2 text-[12px] font-semibold">
                    <Avatar label={handle} className="size-7" />
                    {handle || 'your_handle'}
                    <span className="text-cream/70 font-normal">Just now</span>
                    <X className="ms-auto size-5" />
                </span>
            </div>
            <div className="absolute inset-x-3 bottom-3 flex items-center gap-2">
                <span className="border-cream/70 text-cream/80 flex-1 rounded-full border px-3 py-1.5 text-[12px]">
                    Send message
                </span>
                <Heart className="size-5" />
                <Send className="size-5" />
            </div>
        </VerticalScreen>
    );
}

/** YouTube Shorts: full screen with the title over the bottom and the action rail. */
function YouTubeShort({
    handle,
    caption,
    media,
    frame,
    crops,
    aspects,
    showSafeZones,
    title,
}: LayoutProps & { title: string }) {
    return (
        <VerticalScreen showSafeZones={showSafeZones} platform="youtube">
            <Slides
                media={media}
                frame={frame}
                crops={crops}
                aspects={aspects}
                index={0}
                fill
            />
            <div className="from-ink/85 to-ink/20 absolute inset-0 bg-gradient-to-t via-transparent" />
            <div className="absolute inset-x-0 top-0 flex items-center justify-between p-3 font-semibold">
                <span className="text-base">Shorts</span>
                <Search className="size-5" />
            </div>
            <div className="absolute right-2.5 bottom-16 flex flex-col items-center gap-4">
                <RailAction icon={ThumbsUp} label="Like" />
                <RailAction icon={MessageSquare} label="Comments" />
                <RailAction icon={Share2} label="Share" />
                <RailAction icon={Repeat2} label="Remix" />
            </div>
            <div className="absolute right-14 bottom-3 left-3 grid gap-1.5">
                <span className="flex items-center gap-2 text-[12px] font-semibold">
                    <Avatar label={handle} className="size-6" />
                    {handle ? `@${handle.replace(/^@/, '')}` : '@your_channel'}
                    <span className="bg-cream text-ink rounded-full px-2 py-0.5 text-[10px]">
                        Subscribe
                    </span>
                </span>
                <p className="line-clamp-2 text-[13px] font-medium" dir="auto">
                    {title || 'Your Short’s title'}
                </p>
                <FoldedCaption
                    text={caption}
                    platform="youtube"
                    onMedia
                    className="text-cream/80 text-[11px]"
                />
            </div>
        </VerticalScreen>
    );
}

/** X and Threads: a text-first post with media below. */
function MicroPost({
    name,
    handle,
    caption,
    media,
    frame,
    crops,
    aspects,
    firstComment,
    platform,
}: LayoutProps & { platform: string }) {
    const isX = platform === 'x';

    return (
        <div className="border-border bg-card text-foreground w-full overflow-hidden rounded-xl border text-[13px]">
            <div className="flex gap-2.5 px-3 pt-3 pb-2">
                <Avatar label={name} className="size-9" />
                <div className="grid min-w-0 flex-1 gap-1.5">
                    <span className="flex min-w-0 items-baseline gap-1.5 leading-tight">
                        <span className="truncate font-semibold">
                            {name || 'Your account'}
                        </span>
                        {isX && (
                            <span className="text-muted-foreground truncate text-[12px]">
                                {handle
                                    ? `@${handle.replace(/^@/, '')}`
                                    : '@you'}{' '}
                                · now
                            </span>
                        )}
                        {!isX && (
                            <span className="text-muted-foreground text-[12px]">
                                now
                            </span>
                        )}
                        <MoreHorizontal className="text-muted-foreground ms-auto size-4 shrink-0" />
                    </span>
                    <FoldedCaption text={caption} platform={platform} />
                    {media.length > 0 && (
                        <div className="overflow-hidden rounded-xl">
                            <FeedMedia
                                media={media}
                                frame={frame}
                                crops={crops}
                                aspects={aspects}
                            />
                        </div>
                    )}
                    <span className="text-muted-foreground flex items-center justify-between py-1 pe-6">
                        <MessageCircle className="size-4" />
                        <Repeat2 className="size-4" />
                        <Heart className="size-4" />
                        {isX ? (
                            <Bookmark className="size-4" />
                        ) : (
                            <Send className="size-4" />
                        )}
                    </span>
                </div>
            </div>
            <FirstComment handle={handle} text={firstComment} />
        </div>
    );
}

/** Pinterest: the Pin image, then its title and description. */
function PinterestPin({
    name,
    caption,
    media,
    frame,
    crops,
    aspects,
    title,
    link,
}: LayoutProps & { title: string; link?: string | null }) {
    return (
        <div className="bg-card text-foreground w-full max-w-[300px] text-[13px]">
            <div className="relative overflow-hidden rounded-2xl">
                <Slides
                    media={media}
                    frame={frame}
                    crops={crops}
                    aspects={aspects}
                    index={0}
                />
                <span className="bg-destructive text-cream absolute top-2.5 right-2.5 rounded-full px-3 py-1.5 text-xs font-semibold">
                    Save
                </span>
                {link && (
                    <span className="bg-cream/90 text-ink absolute bottom-2.5 left-2.5 max-w-[80%] truncate rounded-full px-2.5 py-1 text-[11px] font-medium">
                        {link.replace(/^https?:\/\//, '')}
                    </span>
                )}
            </div>
            <div className="grid gap-1 px-1 pt-2">
                <span className="line-clamp-2 font-semibold" dir="auto">
                    {title || 'Your Pin title'}
                </span>
                <FoldedCaption
                    text={caption}
                    platform="pinterest"
                    className="text-muted-foreground text-[12px]"
                />
                <span className="text-muted-foreground flex items-center gap-1.5 text-[11px]">
                    <Avatar label={name} className="size-5 text-[9px]" />
                    {name || 'Your profile'}
                </span>
            </div>
        </div>
    );
}

/** Google Business Profile: an update card on the business listing. */
function GoogleBusinessPost({
    name,
    caption,
    media,
    frame,
    crops,
    aspects,
    link,
}: LayoutProps & { link?: string | null }) {
    return (
        <div className="border-border bg-card text-foreground w-full overflow-hidden rounded-xl border text-[13px]">
            <div className="flex items-center gap-2.5 px-3 pt-3 pb-2">
                <Avatar label={name} className="size-9" />
                <div className="grid min-w-0 leading-tight">
                    <span className="truncate font-semibold">
                        {name || 'Your business'}
                    </span>
                    <span className="text-muted-foreground text-[11px]">
                        Update · Just now
                    </span>
                </div>
            </div>
            <FeedMedia
                media={media.slice(0, 1)}
                frame={frame}
                crops={crops}
                aspects={aspects}
            />
            <FoldedCaption
                text={caption}
                platform="google_business"
                className="px-3 pt-2.5 pb-2"
            />
            {link && (
                <div className="px-3 pb-3">
                    <span className="border-border text-info inline-flex rounded-full border px-4 py-1.5 text-xs font-semibold">
                        Learn more
                    </span>
                </div>
            )}
        </div>
    );
}

/** What the preview is showing, e.g. "Feed post · 4:5", "Reel", "Carousel · 3 slides". */
function describePlacement(
    format: PlatformFormat | undefined,
    media: PreviewAsset[],
    frame: MediaFrame,
): string {
    const label = format?.label ?? 'Post';

    if (media.length === 0) {
        return format && format.min_items > 0
            ? `${label} · needs media`
            : 'Text post';
    }

    if (format?.vertical || isVideoAsset(media[0])) {
        return media.length > 1 && !isVideoAsset(media[0])
            ? `${label} · ${media.length} slides`
            : label;
    }

    return media.length > 1
        ? `${label} · ${media.length} slides`
        : `${label} · ${describeRatio(frame.ratio)}`;
}

/** First line of a caption, cut to a platform's title limit (YouTube, Pinterest). */
function shortTitle(caption: string, limit: number | null): string {
    const first = caption.trim().split('\n')[0]?.trim() ?? '';

    return limit && first.length > limit
        ? `${first.slice(0, limit - 1).trimEnd()}…`
        : first;
}

/**
 * Live, Planable-style preview of how a post renders on one channel. Purely presentational —
 * driven by the editor's own state (caption, hashtags, media, format, framing), so it updates as
 * the user types with no network round-trip. Since Step M-2.2 / M-2.3 each channel can carry its
 * own caption, hashtags, link, first comment and format (`target`), and everything about the
 * platform (label, caption fold, link and hashtag policy, formats) comes from the capability
 * registry (`usePlatforms()`). Media is framed by the same rules the publisher crops with
 * (`lib/media-format.ts`), and the caption is the exact text that publishes.
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
    /** Outline where full-screen app chrome covers the media. */
    showSafeZones?: boolean;
    className?: string;
}) {
    const platform = target.platform;
    const registry = usePlatforms();
    const capability = capabilityOf(registry, platform);
    const aspects = useMediaAspects();
    const media = publishableMedia(assets);
    const first = media[0];
    const kinds = media.map((asset) =>
        isVideoAsset(asset) ? 'video' : 'image',
    );
    const format = resolveFormat(capability, target.format, kinds);
    const frame = frameFor(
        platform,
        mediaFormat?.[platform],
        aspects.aspectOf(first),
        first ? isVideoAsset(first) : false,
        format,
    );
    const ownCaption = target.caption ?? caption;
    const published =
        target.publishedCaption ??
        publishedCaption(
            ownCaption,
            target.hashtags ?? hashtags,
            capability,
            target.link,
        );
    const firstComment = capability.first_comment ? target.firstComment : null;

    const props: LayoutProps = {
        handle: target.handle,
        name: target.display_name || target.handle,
        caption: published,
        media,
        frame,
        crops,
        aspects,
        musicName,
        locationName,
        showSafeZones,
        firstComment,
    };
    const title = shortTitle(ownCaption, capability.title_limit);
    const Icon = platformIcon(platform);
    const isStory = format?.crop_to === '9:16';

    const layout = (() => {
        switch (true) {
            case platform === 'tiktok':
                return <TikTokPost {...props} />;
            case platform === 'youtube':
                return <YouTubeShort {...props} title={title} />;
            case isStory:
                return <StoryScreen {...props} platform={platform} />;
            case Boolean(format?.vertical):
                return <InstagramReel {...props} />;
            case platform === 'instagram':
                return <InstagramFeed {...props} />;
            case platform === 'linkedin':
                return <LinkedInPost {...props} />;
            case platform === 'x' || platform === 'threads':
                return <MicroPost {...props} platform={platform} />;
            case platform === 'pinterest':
                return (
                    <PinterestPin {...props} title={title} link={target.link} />
                );
            case platform === 'google_business':
                return <GoogleBusinessPost {...props} link={target.link} />;
            default:
                return <FacebookPost {...props} />;
        }
    })();

    return (
        <div
            className={cn(
                'flex w-full flex-col items-center gap-2.5',
                className,
            )}
        >
            <span className="eyebrow text-muted-foreground inline-flex items-center gap-1.5">
                <Icon className="size-3.5" />
                {platformLabelOf(registry, platform)}
                <span aria-hidden>·</span>
                <span>{describePlacement(format, media, frame)}</span>
            </span>
            {layout}
        </div>
    );
}
