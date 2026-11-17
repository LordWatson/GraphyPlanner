import { describe, expect, it } from 'vite-plus/test';
import type {
    ChannelState,
    ChecklistItem,
    TargetAccountData,
} from '@/components/post-editor/types';
import { EMPTY_CHANNEL } from '@/components/post-editor/types';
import type { LiveState } from '@/lib/live-checklist';
import { liveChecklist } from '@/lib/live-checklist';
import type { PlatformCapability, PlatformRegistry } from '@/lib/platforms';
import type { StoryExtras } from '@/lib/story-extras';

const format = (key: string, label: string, crop_to: string | null = null) => ({
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

const instagram: PlatformCapability = {
    value: 'instagram',
    label: 'Instagram',
    enabled: true,
    caption_limit: 2200,
    title_limit: null,
    hashtags: true,
    max_hashtags: 30,
    links_in_caption: false,
    link_field: false,
    first_comment: true,
    alt_text: true,
    text_only: false,
    keep_ai_originals: false,
    required_settings: [],
    settings: {},
    media_ratios: ['original'],
    caption_fold: { chars: 125, lines: 2 },
    default_format: 'post',
    formats: [format('post', 'Post'), format('story', 'Story', '9:16')],
    story_extras: true,
};

const registry: PlatformRegistry = { instagram };

const account: TargetAccountData = {
    id: 1,
    platform: 'instagram',
    platform_label: 'Instagram',
    handle: '@acme',
    timezone: 'Europe/London',
    caption_limit: 2200,
    max_hashtags: 30,
    is_text_only_capable: false,
};

const serverItems: ChecklistItem[] = [
    { key: 'caption', label: 'Every channel has a caption', passed: true },
    { key: 'links', label: 'Links will show', passed: true, soft: true },
];

const link: StoryExtras = { link: { url: 'https://acme.test/sale', text: '' } };

const storyHandoff: NonNullable<LiveState['storyHandoff']> = {
    defaultRecipient: 'team',
    canChooseRecipient: true,
    clientCanBeNotified: true,
    clientName: 'Acme',
    hasPoster: true,
};

function check(
    channel: Partial<ChannelState>,
    overrides: Partial<LiveState> = {},
    items: ChecklistItem[] = serverItems,
): ChecklistItem[] {
    return liveChecklist(items, {
        caption: 'Hello',
        selectedAccounts: [account],
        schedule: [{ date: '2027-05-07', time: '10:00' }],
        mediaCount: 1,
        mediaKinds: ['image'],
        channels: { 1: { ...EMPTY_CHANNEL, ...channel } },
        registry,
        storyHandoff,
        ...overrides,
    });
}

const find = (items: ChecklistItem[], key: string) =>
    items.find((item) => item.key === key);

describe('liveChecklist: Story extras', () => {
    it('has no story items when no channel is posted by hand', () => {
        const serverSent: ChecklistItem[] = [
            ...serverItems,
            { key: 'story_extras', label: 'Story extras', passed: false },
            {
                key: 'story_handoff',
                label: 'Handoff',
                passed: false,
                soft: true,
            },
        ];

        for (const channel of [
            {}, // A feed post.
            { format: 'story' }, // A plain Story: auto-publishes.
            { format: 'post', storyExtras: link }, // Extras left behind on a feed post.
            { format: 'story', storyExtras: { placement_note: 'Top' } }, // A note is not an extra.
        ] as Partial<ChannelState>[]) {
            const keys = check(channel, {}, serverSent).map((item) => item.key);

            expect(keys).toEqual(['caption', 'links']);
        }
    });

    it('has no story items on a platform without story extras', () => {
        const items = check(
            { format: 'story', storyExtras: link },
            {
                registry: {
                    instagram: { ...instagram, story_extras: false },
                },
            },
        );

        expect(items.map((item) => item.key)).toEqual(['caption', 'links']);
    });

    it('appends both items the moment a channel is posted by hand', () => {
        const items = check({ format: 'story', storyExtras: link });

        expect(items.map((item) => item.key)).toEqual([
            'caption',
            'links',
            'story_extras',
            'story_handoff',
        ]);
        expect(find(items, 'story_extras')).toMatchObject({ passed: true });
        expect(find(items, 'story_extras')?.detail).toBeUndefined();
        expect(find(items, 'story_handoff')).toMatchObject({
            passed: true,
            soft: true,
        });
    });

    it('updates the server’s own story items in place instead of repeating them', () => {
        const items = check(
            {
                format: 'story',
                storyExtras: { link: { url: '', text: '' } },
            },
            {},
            [
                ...serverItems,
                { key: 'story_extras', label: 'From the server', passed: true },
            ],
        );

        expect(items.filter((item) => item.key === 'story_extras')).toEqual([
            {
                key: 'story_extras',
                label: 'From the server',
                passed: false,
                detail: 'Instagram (@acme): the link sticker needs a link.',
                fixes: undefined,
            },
        ]);
        expect(find(items, 'story_handoff')).toBeDefined();
    });

    it('fails story_extras, naming the channel, until the link is complete', () => {
        const incomplete = find(
            check({
                format: 'story',
                storyExtras: { link: { url: 'http://acme.test', text: '' } },
            }),
            'story_extras',
        );

        expect(incomplete).toMatchObject({
            passed: false,
            detail: 'Instagram (@acme): the link must be a full address starting with https://.',
        });
        expect(incomplete?.soft).toBeUndefined();

        expect(
            find(check({ format: 'story', storyExtras: link }), 'story_extras'),
        ).toMatchObject({ passed: true });
    });

    it('warns when no one is assigned, and passes when someone is', () => {
        const unassigned = find(
            check(
                { format: 'story', storyExtras: link },
                { storyHandoff: { ...storyHandoff, hasPoster: false } },
            ),
            'story_handoff',
        );

        expect(unassigned).toMatchObject({
            passed: false,
            soft: true,
            detail: 'Instagram (@acme): no one is assigned, so everyone on the team will be asked.',
        });

        expect(
            find(
                check({ format: 'story', storyExtras: link }),
                'story_handoff',
            ),
        ).toMatchObject({ passed: true });
    });

    it('warns when the client posts it but has no contact', () => {
        const state = {
            storyHandoff: {
                ...storyHandoff,
                clientCanBeNotified: false,
                hasPoster: false,
            },
        };
        const item = find(
            check(
                {
                    format: 'story',
                    storyExtras: link,
                    handoffRecipient: 'client',
                },
                state,
            ),
            'story_handoff',
        );

        expect(item).toMatchObject({
            passed: false,
            detail: 'Instagram (@acme): Acme has no contact to send the story to, so your team will be asked instead.',
        });

        // With a contact, the client is enough: nobody on the team needs to be assigned.
        expect(
            find(
                check(
                    {
                        format: 'story',
                        storyExtras: link,
                        handoffRecipient: 'client',
                    },
                    { storyHandoff: { ...storyHandoff, hasPoster: false } },
                ),
                'story_handoff',
            ),
        ).toMatchObject({ passed: true });
    });

    it('follows the client’s default recipient, and the team when there is no choice', () => {
        const noContact = {
            ...storyHandoff,
            defaultRecipient: 'client' as const,
            clientCanBeNotified: false,
        };

        expect(
            find(
                check(
                    { format: 'story', storyExtras: link },
                    { storyHandoff: noContact },
                ),
                'story_handoff',
            )?.detail,
        ).toContain('Acme has no contact');

        // A business workspace has no client to hand a Story to.
        expect(
            find(
                check(
                    {
                        format: 'story',
                        storyExtras: link,
                        handoffRecipient: 'client',
                    },
                    {
                        storyHandoff: {
                            ...noContact,
                            canChooseRecipient: false,
                        },
                    },
                ),
                'story_handoff',
            ),
        ).toMatchObject({ passed: true });
    });

    it('does not warn about the link on a Story that carries it as a sticker', () => {
        const items = check(
            { format: 'story', storyExtras: link },
            { link: 'https://acme.test/sale' },
        );

        expect(find(items, 'links')).toMatchObject({ passed: true });
        expect(find(items, 'links')?.detail).toBeUndefined();
    });

    it('still warns about the link on a plain Instagram channel', () => {
        const plain = find(
            check({}, { link: 'https://acme.test/sale' }),
            'links',
        );

        expect(plain?.passed).toBe(false);
        expect(plain?.detail).toMatch(
            /^Instagram \(@acme\): the link won’t be posted/,
        );

        // A hand-posted Story with some other sticker, but no link sticker, still loses the link.
        expect(
            find(
                check(
                    { format: 'story', storyExtras: { hashtag: 'sale' } },
                    { link: 'https://acme.test/sale' },
                ),
                'links',
            )?.passed,
        ).toBe(false);
    });
});
