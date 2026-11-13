import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vite-plus/test';
import type {
    ChannelState,
    TargetAccountData,
} from '@/components/post-editor/types';
import { EMPTY_CHANNEL } from '@/components/post-editor/types';
import type { PlatformCapability, PlatformRegistry } from '@/lib/platforms';

const { registry } = vi.hoisted(() => {
    const format = (key: string, label: string, crop_to: string | null) => ({
        key,
        label,
        media: ['image', 'video'] as ('image' | 'video')[],
        min_items: 1,
        max_items: 1,
        aspect: null,
        crop_to,
        fill_screen: null,
        vertical: key === 'story',
        min_video_seconds: null,
        max_video_seconds: null,
        max_image_mb: null,
        max_video_mb: null,
    });
    const platform = (
        value: string,
        label: string,
        story_extras: boolean,
    ): PlatformCapability => ({
        value,
        label,
        enabled: true,
        caption_limit: 2200,
        title_limit: null,
        hashtags: true,
        max_hashtags: 30,
        links_in_caption: false,
        link_field: false,
        first_comment: false,
        alt_text: true,
        text_only: false,
        keep_ai_originals: false,
        required_settings: [],
        settings: {},
        media_ratios: ['original'],
        caption_fold: { chars: 125, lines: 2 },
        default_format: 'post',
        formats: [
            format('post', 'Post', null),
            format('story', 'Story', '9:16'),
        ],
        story_extras,
    });
    const registry: PlatformRegistry = {
        instagram: platform('instagram', 'Instagram', true),
        // Offers a Story format, but not the extras (the lane is Instagram-only).
        facebook: platform('facebook', 'Facebook', false),
    };

    return { registry };
});

// Only the Inertia-backed hook is replaced; the capability helpers stay real.
vi.mock('@/lib/platforms', async () => ({
    ...(await vi.importActual<typeof import('@/lib/platforms')>(
        '@/lib/platforms',
    )),
    usePlatforms: () => registry,
}));

import type { StoryPanelContext } from '@/components/post-editor/types';
import { ChannelComposer } from '@/components/post-editor/composer/channel-composer';

const account = (platform: string): TargetAccountData => ({
    id: 1,
    platform,
    platform_label: registry[platform].label,
    handle: '@acme',
    timezone: 'Europe/London',
    caption_limit: 2200,
    max_hashtags: 30,
    is_text_only_capable: false,
});

const story: StoryPanelContext = {
    options: {
        default_recipient: 'team',
        can_choose_recipient: true,
        client_can_be_notified: true,
        posters: [{ id: 5, name: 'Ada Lovelace' }],
        creator_id: 5,
        window_minutes: 120,
    },
    assigneeId: '5',
    clientName: 'Acme',
};

const master = {
    caption: 'Hello',
    hashtags: [],
    link: 'https://acme.test/sale',
    firstComment: '',
};

function renderComposer(
    channel: Partial<ChannelState>,
    {
        platform = 'instagram',
        withStory = true,
        onChannelChange = vi.fn(),
    }: {
        platform?: string;
        withStory?: boolean;
        onChannelChange?: (id: number, next: ChannelState) => void;
    } = {},
) {
    return render(
        <ChannelComposer
            account={account(platform)}
            master={master}
            channels={{ 1: { ...EMPTY_CHANNEL, ...channel } }}
            onChannelChange={onChannelChange}
            mediaKinds={['image']}
            hashtagSuggestions={[]}
            story={withStory ? story : undefined}
        />,
    );
}

const panel = () => screen.queryByRole('region', { name: 'Story extras' });

describe('ChannelComposer: Story extras panel', () => {
    it('shows the panel for a Story on a platform that takes extras', () => {
        renderComposer({ format: 'story' });

        expect(panel()).toBeInTheDocument();
        expect(screen.getByText('Auto-publish')).toBeInTheDocument();
    });

    it('hides the panel for a feed format, and for Auto', () => {
        const feed = renderComposer({ format: 'post' });
        expect(panel()).not.toBeInTheDocument();
        feed.unmount();

        // Story is only ever an explicit choice, never inferred from the media.
        renderComposer({ format: null });
        expect(panel()).not.toBeInTheDocument();
    });

    it('hides the panel on a platform without story extras', () => {
        renderComposer({ format: 'story' }, { platform: 'facebook' });

        expect(panel()).not.toBeInTheDocument();
    });

    it('leaves the panel out when the page gives it no story context', () => {
        renderComposer({ format: 'story' }, { withStory: false });

        expect(panel()).not.toBeInTheDocument();
    });

    it('hands the panel the channel’s own link, else the post’s', async () => {
        const onChannelChange = vi.fn();
        renderComposer(
            { format: 'story', storyExtras: { link: { url: '', text: '' } } },
            { onChannelChange },
        );

        await userEvent.click(
            screen.getByRole('button', { name: /use the post.s link/i }),
        );

        expect(onChannelChange).toHaveBeenCalledWith(
            1,
            expect.objectContaining({
                format: 'story',
                storyExtras: {
                    link: { url: 'https://acme.test/sale', text: '' },
                },
            }),
        );
    });

    it('keeps the format, the extras and who posts it on Reset', async () => {
        const onChannelChange = vi.fn();
        const storyExtras = {
            link: { url: 'https://acme.test/sale', text: 'Shop now' },
            placement_note: 'Bottom centre',
        };
        renderComposer(
            {
                caption: 'Just for Instagram',
                hashtags: ['sale'],
                link: 'https://acme.test/other',
                firstComment: 'First!',
                format: 'story',
                storyExtras,
                handoffRecipient: 'client',
            },
            { onChannelChange },
        );

        await userEvent.click(
            screen.getByRole('button', { name: 'Reset Instagram' }),
        );

        expect(onChannelChange).toHaveBeenCalledTimes(1);
        expect(onChannelChange).toHaveBeenCalledWith(1, {
            ...EMPTY_CHANNEL,
            format: 'story',
            storyExtras,
            handoffRecipient: 'client',
        });
    });

    it('does not offer Reset for extras alone: they are not an override', () => {
        renderComposer({
            format: 'story',
            storyExtras: { hashtag: 'sale' },
            handoffRecipient: 'client',
        });

        expect(
            screen.queryByRole('button', { name: /^Reset/ }),
        ).not.toBeInTheDocument();
    });
});
