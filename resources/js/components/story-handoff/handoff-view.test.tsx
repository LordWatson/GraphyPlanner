import { act, fireEvent, render, screen } from '@testing-library/react';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import type { Mock } from 'vite-plus/test';

const { confirmDialog, fetchBlob, post, toast } = vi.hoisted(() => ({
    confirmDialog: vi.fn(),
    fetchBlob: vi.fn(),
    post: vi.fn(),
    toast: { success: vi.fn(), error: vi.fn() },
}));

vi.mock('@/lib/http', () => ({ fetchBlob, http: { post } }));
vi.mock('sonner', () => ({ toast }));
vi.mock('@/components/confirm-dialog', () => ({ confirmDialog }));

import { HandoffExtrasList } from '@/components/story-handoff/handoff-parts';
import type { HandoffActions } from '@/components/story-handoff/handoff-view';
import { HandoffView } from '@/components/story-handoff/handoff-view';
import type { HandoffData } from '@/components/story-handoff/types';

// Clicks go through fireEvent here: user-event swaps in its own navigator.clipboard.

const LINK = 'https://acme.test/sale';
const MEDIA_URL = '/story/token/media';

const base: HandoffData = {
    state: 'ready',
    late: false,
    early: false,
    due_at: '2027-05-07T09:00:00Z',
    due: { date: '2027-05-07', time: '10:00', timezone: 'Europe/London' },
    client: { name: 'Acme' },
    account: {
        platform: 'instagram',
        platform_label: 'Instagram',
        handle: '@acme',
    },
    posted_by: 'team',
    poster_name: 'Ada Lovelace',
    extras: [
        { key: 'link', label: 'Link', value: LINK },
        { key: 'poll', label: 'Poll', value: 'Which one? Red / Blue' },
    ],
    link: LINK,
    placement_note: 'Link sticker bottom centre.',
    media: {
        kind: 'image',
        url: MEDIA_URL,
        mime_type: 'image/jpeg',
        filename: 'acme-story.jpg',
        size: 1024,
        shareable: true,
    },
    completed: null,
    can_confirm_late: false,
};

const handoff = (overrides: Partial<HandoffData> = {}): HandoffData => ({
    ...base,
    ...overrides,
});

const blobResult = () => ({
    ok: true,
    blob: new Blob(['x'], { type: 'image/jpeg' }),
});

let share: ReturnType<typeof vi.fn>;
let canShare: ReturnType<typeof vi.fn>;
let writeText: ReturnType<typeof vi.fn>;
let actions: { [K in keyof HandoffActions]: Mock<HandoffActions[K]> };

function define(target: object, name: string, value: unknown): void {
    Object.defineProperty(target, name, {
        configurable: true,
        writable: true,
        value,
    });
}

function setVisibility(state: 'visible' | 'hidden'): void {
    Object.defineProperty(document, 'visibilityState', {
        configurable: true,
        get: () => state,
    });
    act(() => {
        document.dispatchEvent(new Event('visibilitychange'));
    });
}

beforeEach(() => {
    share = vi.fn(() => Promise.resolve());
    canShare = vi.fn(() => true);
    writeText = vi.fn(() => Promise.resolve());
    actions = { onShared: vi.fn(), onPosted: vi.fn(), onProblem: vi.fn() };

    define(navigator, 'share', share);
    define(navigator, 'canShare', canShare);
    define(navigator, 'clipboard', { writeText });
    // jsdom has neither.
    define(
        URL,
        'createObjectURL',
        vi.fn(() => 'blob:story'),
    );
    define(URL, 'revokeObjectURL', vi.fn());

    fetchBlob.mockReset();
    fetchBlob.mockImplementation(() => Promise.resolve(blobResult()));
    toast.success.mockClear();
    toast.error.mockClear();
    confirmDialog.mockReset();
    confirmDialog.mockResolvedValue(true);
});

afterEach(() => {
    for (const name of ['share', 'canShare', 'clipboard']) {
        delete (navigator as unknown as Record<string, unknown>)[name];
    }

    for (const name of ['createObjectURL', 'revokeObjectURL']) {
        delete (URL as unknown as Record<string, unknown>)[name];
    }

    delete (document as unknown as Record<string, unknown>).visibilityState;
});

function renderView(
    overrides: Partial<HandoffData> = {},
    props: Partial<Parameters<typeof HandoffView>[0]> = {},
) {
    return render(
        <HandoffView
            handoff={handoff(overrides)}
            actions={actions}
            {...props}
        />,
    );
}

const shareButton = () =>
    screen.queryByRole('button', { name: /share to instagram|share again/i });
const loadingButton = () =>
    screen.queryByRole('button', { name: /getting the media ready/i });
const postedButton = () => screen.getByRole('button', { name: /posted it/i });
const saveLink = () => screen.getByRole('link', { name: 'Save media' });
const guide = () => screen.queryByText('Save the media to your phone.');
/** The emphasised "I've posted it" is the filled success button. */
const EMPHASISED = 'bg-success';

/** Render and wait for the media to be in hand, with the Share button live. */
async function renderReady(overrides: Partial<HandoffData> = {}) {
    const view = renderView(overrides);

    expect(
        await screen.findByRole('button', { name: 'Share to Instagram' }),
    ).toBeEnabled();

    return view;
}

describe('HandoffView: sharing or saving', () => {
    it('holds the Share button until the media has downloaded', async () => {
        let finish: (result: unknown) => void = () => undefined;
        fetchBlob.mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        renderView();

        expect(loadingButton()).toBeDisabled();
        expect(shareButton()).not.toBeInTheDocument();
        expect(guide()).not.toBeInTheDocument();
        expect(fetchBlob).toHaveBeenCalledWith(MEDIA_URL, expect.anything());

        await act(async () => finish(blobResult()));

        expect(shareButton()).toBeEnabled();
        expect(shareButton()).toHaveTextContent('Share to Instagram');
        expect(loadingButton()).not.toBeInTheDocument();
        expect(saveLink()).toHaveAttribute('href', MEDIA_URL);
        expect(saveLink()).toHaveAttribute('download', 'acme-story.jpg');
        expect(guide()).not.toBeInTheDocument();
        expect(
            screen.getByText(/sharing copies the link/i),
        ).toBeInTheDocument();
    });

    it('leads with Save media when the browser cannot share the file', async () => {
        canShare.mockReturnValue(false);
        renderView();

        expect(
            await screen.findByText('Save the media to your phone.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Open Instagram and start a story.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Pick it from your camera roll.'),
        ).toBeInTheDocument();
        expect(shareButton()).not.toBeInTheDocument();
        expect(loadingButton()).not.toBeInTheDocument();
        expect(saveLink()).toHaveAttribute('href', MEDIA_URL);
    });

    it('leads with Save media when the browser has no share at all', async () => {
        define(navigator, 'share', undefined);
        renderView();

        expect(
            await screen.findByText('Save the media to your phone.'),
        ).toBeInTheDocument();
        expect(shareButton()).not.toBeInTheDocument();
    });

    it('never downloads a file too big to share, and leads with Save media', () => {
        renderView({ media: { ...base.media!, shareable: false } });

        expect(fetchBlob).not.toHaveBeenCalled();
        expect(guide()).toBeInTheDocument();
        expect(shareButton()).not.toBeInTheDocument();
        expect(loadingButton()).not.toBeInTheDocument();
        expect(saveLink()).toBeInTheDocument();
        // Shown straight from the media route.
        expect(
            screen.getByRole('img', { name: 'The story image for @acme' }),
        ).toHaveAttribute('src', MEDIA_URL);
    });

    it('leads with Save media when the download fails', async () => {
        fetchBlob.mockImplementation(() =>
            Promise.resolve({ ok: false, status: 500, aborted: false }),
        );
        renderView();

        expect(
            await screen.findByText('Save the media to your phone.'),
        ).toBeInTheDocument();
        expect(shareButton()).not.toBeInTheDocument();
        expect(saveLink()).toBeInTheDocument();
    });

    it('counts saving the media as having started', async () => {
        canShare.mockReturnValue(false);
        renderView();
        await screen.findByText('Save the media to your phone.');

        // jsdom would try to navigate on a real anchor click.
        saveLink().addEventListener('click', (event) => event.preventDefault());
        fireEvent.click(saveLink());
        fireEvent.click(saveLink());

        expect(actions.onShared).toHaveBeenCalledTimes(1);
        expect(postedButton()).toHaveClass(EMPHASISED);
    });

    it('offers no share or save when the story has no media', () => {
        renderView({ media: null });

        expect(
            screen.getByText('This story has no media attached.'),
        ).toBeInTheDocument();
        expect(shareButton()).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Save media' }),
        ).not.toBeInTheDocument();
        expect(postedButton()).toBeEnabled();
    });
});

describe('HandoffView: ready', () => {
    it('shares, then makes "I’ve posted it" the next thing to do', async () => {
        await renderReady();

        expect(postedButton()).not.toHaveClass(EMPHASISED);

        // A double tap still tells the server once.
        fireEvent.click(shareButton()!);
        fireEvent.click(shareButton()!);

        expect(actions.onShared).toHaveBeenCalledTimes(1);
        expect(share).toHaveBeenCalledTimes(1);
        expect(writeText).toHaveBeenCalledWith(LINK);
        expect(share).toHaveBeenCalledWith({
            files: [expect.objectContaining({ name: 'acme-story.jpg' })],
        });

        expect(
            await screen.findByRole('button', { name: 'Share again' }),
        ).toBeEnabled();
        expect(postedButton()).toHaveClass(EMPHASISED);
        expect(toast.success).toHaveBeenCalledWith(
            'Link copied. Paste it into the link sticker.',
        );
        expect(actions.onPosted).not.toHaveBeenCalled();

        await act(async () => {
            fireEvent.click(postedButton());
        });

        // They shared from here, so there is nothing to double-check.
        expect(confirmDialog).not.toHaveBeenCalled();
        expect(actions.onPosted).toHaveBeenCalledTimes(1);
    });

    it('asks first when marked as posted without sharing from here', async () => {
        await renderReady();

        await act(async () => {
            fireEvent.click(postedButton());
        });

        expect(confirmDialog).toHaveBeenCalledWith({
            title: 'Posted it already?',
            description:
                'Only say so once the story is live on @acme. We can’t check Instagram, so we’ll take your word for it.',
            confirmLabel: 'I’ve posted it',
        });
        expect(actions.onPosted).toHaveBeenCalledTimes(1);
        expect(actions.onShared).not.toHaveBeenCalled();
    });

    it('does not mark it as posted when the poster says no', async () => {
        confirmDialog.mockResolvedValue(false);
        await renderReady();

        await act(async () => {
            fireEvent.click(postedButton());
        });

        expect(confirmDialog).toHaveBeenCalledTimes(1);
        expect(actions.onPosted).not.toHaveBeenCalled();
    });

    it('opens the share sheet once on a double tap', async () => {
        let finish: () => void = () => undefined;
        share.mockImplementation(
            () =>
                new Promise<void>((resolve) => {
                    finish = resolve;
                }),
        );
        await renderReady();

        const button = shareButton()!;
        fireEvent.click(button);
        fireEvent.click(button);

        expect(share).toHaveBeenCalledTimes(1);
        expect(button).toBeDisabled();

        await act(async () => finish());

        expect(shareButton()).toBeEnabled();
        expect(shareButton()).toHaveTextContent('Share again');
    });

    it('changes nothing when the poster closes the share sheet', async () => {
        share.mockImplementation(() =>
            Promise.reject(new DOMException('Share cancelled', 'AbortError')),
        );
        await renderReady();

        await act(async () => {
            fireEvent.click(shareButton()!);
        });

        expect(share).toHaveBeenCalledTimes(1);
        expect(shareButton()).toHaveTextContent('Share to Instagram');
        expect(postedButton()).not.toHaveClass(EMPHASISED);
        expect(toast.success).not.toHaveBeenCalled();
        expect(toast.error).not.toHaveBeenCalled();

        // Coming back to the tab after closing the sheet is not "shared".
        setVisibility('hidden');
        setVisibility('visible');

        expect(postedButton()).not.toHaveClass(EMPHASISED);
        expect(shareButton()).toHaveTextContent('Share to Instagram');
        expect(shareButton()).toBeEnabled();
    });

    it('leads with Save media instead when sharing fails', async () => {
        share.mockImplementation(() => Promise.reject(new Error('boom')));
        await renderReady();

        await act(async () => {
            fireEvent.click(shareButton()!);
        });

        expect(toast.error).toHaveBeenCalledWith(
            'Sharing didn’t work here. Save the media instead.',
        );
        expect(postedButton()).not.toHaveClass(EMPHASISED);
        expect(shareButton()).not.toBeInTheDocument();
        expect(guide()).toBeInTheDocument();
        expect(saveLink()).toHaveAttribute('href', MEDIA_URL);
    });

    it('does not move on for a tab switch, or while the share sheet is open', async () => {
        // A share sheet that never reports back.
        share.mockImplementation(() => new Promise(() => undefined));
        await renderReady();

        setVisibility('hidden');
        setVisibility('visible');
        expect(postedButton()).not.toHaveClass(EMPHASISED);

        fireEvent.click(shareButton()!);
        expect(shareButton()).toBeDisabled();

        setVisibility('hidden');
        setVisibility('visible');

        // Nothing confirmed the share, so nothing moves on; but Share is usable again.
        expect(postedButton()).not.toHaveClass(EMPHASISED);
        expect(shareButton()).toBeEnabled();
    });

    it('says which iPhone step saves a video', async () => {
        define(navigator, 'maxTouchPoints', 5);
        vi.spyOn(navigator, 'userAgent', 'get').mockReturnValue('iPhone');
        canShare.mockReturnValue(false);
        renderView({ media: { ...base.media!, kind: 'video' } });

        expect(
            await screen.findByText(
                'On iPhone, open the saved video in Files, tap Share, then Save Video.',
            ),
        ).toBeInTheDocument();
        expect(screen.getAllByRole('listitem')).toHaveLength(5);

        vi.restoreAllMocks();
    });

    it('takes a problem report of at least three characters', async () => {
        await renderReady();

        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Something went wrong' }),
        );

        const note = screen.getByLabelText('What went wrong?');
        const submit = screen.getByRole('button', { name: 'Tell the team' });

        expect(submit).toBeDisabled();

        fireEvent.change(note, { target: { value: ' ab ' } });
        expect(submit).toBeDisabled();

        fireEvent.change(note, { target: { value: 'It froze' } });
        expect(submit).toBeEnabled();

        fireEvent.click(submit);

        expect(actions.onProblem).toHaveBeenCalledTimes(1);
        expect(actions.onProblem).toHaveBeenCalledWith('It froze');
        expect(actions.onPosted).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Something went wrong' }),
        ).toHaveFocus();
    });

    it('says when it is late, and when it is early', async () => {
        const late = await renderReady({ late: true });
        expect(screen.getByText('This was due at 10:00.')).toBeInTheDocument();
        expect(screen.queryByText(/^Scheduled for/)).not.toBeInTheDocument();
        late.unmount();

        await renderReady({ early: true });
        expect(screen.getByText('Scheduled for 10:00.')).toBeInTheDocument();
        expect(screen.queryByText(/^This was due/)).not.toBeInTheDocument();
    });

    it('shows the server’s error, the extras and the placement note', async () => {
        renderView({}, { errors: { handoff: 'This link has expired.' } });
        await screen.findByRole('button', { name: 'Share to Instagram' });

        expect(screen.getByText('This link has expired.')).toBeInTheDocument();
        expect(
            screen.getByRole('heading', {
                name: 'Then add these in Instagram',
            }),
        ).toBeInTheDocument();
        expect(screen.getAllByRole('listitem')).toHaveLength(2);
        expect(
            screen.getByText('Link sticker bottom centre.'),
        ).toBeInTheDocument();
    });

    it('locks "I’ve posted it" while it is being saved', async () => {
        renderView({}, { pending: 'posted' });
        await screen.findByRole('button', { name: 'Share to Instagram' });

        expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
    });

    it('says "Sending…" only on the problem form while a report is sent', async () => {
        const view = await renderReady();

        fireEvent.click(
            screen.getByRole('button', { name: 'Something went wrong' }),
        );
        fireEvent.change(screen.getByLabelText('What went wrong?'), {
            target: { value: 'It froze' },
        });
        view.rerender(
            <HandoffView
                handoff={handoff()}
                actions={actions}
                pending="problem"
            />,
        );

        expect(screen.getByRole('button', { name: 'Sending…' })).toBeDisabled();
        expect(postedButton()).toBeDisabled();
        expect(postedButton()).toHaveTextContent('I’ve posted it');
    });
});

describe('HandoffView: ended', () => {
    const noActions = () => {
        expect(shareButton()).not.toBeInTheDocument();
        expect(loadingButton()).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /posted it/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Save media' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Something went wrong' }),
        ).not.toBeInTheDocument();
    };

    it('shows a posted story read-only, with who and when', () => {
        renderView({
            state: 'posted',
            completed: { at: '2027-05-07T09:05:00Z', by: 'Ada Lovelace' },
        });

        expect(screen.getByText('Posted')).toBeInTheDocument();
        expect(
            screen.getByText(
                /Marked as posted on \d{1,2} May 2027,? \d{2}:\d{2} by Ada Lovelace\.\s+Nothing more to do here\./,
            ),
        ).toBeInTheDocument();
        noActions();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
        // Nothing to share, so nothing is downloaded.
        expect(fetchBlob).not.toHaveBeenCalled();
    });

    it('reads as a sentence when nobody is named', () => {
        renderView({ state: 'posted', completed: null });

        expect(
            screen.getByText(
                /^Marked as posted\.\s+Nothing more to do here\.$/,
            ),
        ).toBeInTheDocument();
    });

    it('says a missed story’s time has passed, with no way to confirm it', () => {
        renderView({ state: 'missed' });

        expect(
            screen.getByText('The time to post this has passed.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Ask your team to send a new reminder.'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'It did go out' }),
        ).not.toBeInTheDocument();
        noActions();
    });

    it('lets a missed story be confirmed late when the server allows it', () => {
        renderView(
            { state: 'missed', can_confirm_late: true },
            { errors: { handoff: 'Too late for that.' } },
        );

        expect(
            screen.getByText('The time to post this has passed.'),
        ).toBeInTheDocument();
        expect(screen.getByText('Too late for that.')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'It did go out' }));

        expect(actions.onPosted).toHaveBeenCalledTimes(1);
        noActions();
    });

    it('says so for a reported problem and for a story that went out plain', () => {
        const problem = renderView({ state: 'problem' });
        expect(screen.getByText('A problem was reported.')).toBeInTheDocument();
        noActions();
        problem.unmount();

        renderView({ state: 'published_plain' });
        expect(
            screen.getByText('This went out without its extras.'),
        ).toBeInTheDocument();
        noActions();
    });

    it('links to the next story and to the post', () => {
        renderView(
            {
                state: 'posted',
                completed: { at: '2027-05-07T09:05:00Z', by: null },
            },
            {
                next: {
                    url: '/story/next-token',
                    handle: '@other',
                    client_name: 'Other',
                },
                postUrl: '/posts/9/edit',
            },
        );

        expect(
            screen.getByRole('link', { name: 'Next story: @other' }),
        ).toHaveAttribute('href', '/story/next-token');
        expect(
            screen.getByRole('link', { name: 'Open the post in Alima' }),
        ).toHaveAttribute('href', '/posts/9/edit');
    });

    it('has no next link when there is nothing next', () => {
        renderView({ state: 'posted' }, { next: null });

        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
});

describe('HandoffExtrasList', () => {
    it('renders nothing with no extras', () => {
        const { container } = render(<HandoffExtrasList extras={[]} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('copies the value of the row whose button was tapped', async () => {
        render(<HandoffExtrasList extras={base.extras} />);

        fireEvent.click(screen.getByRole('button', { name: 'Copy poll' }));

        expect(writeText).toHaveBeenLastCalledWith('Which one? Red / Blue');
        expect(
            await screen.findByRole('button', { name: 'Copy poll' }),
        ).toHaveTextContent('Copied');
        expect(toast.success).toHaveBeenLastCalledWith('Poll copied.');
        expect(
            screen.getByRole('button', { name: 'Copy link' }),
        ).toHaveTextContent(/^Copy$/);

        fireEvent.click(screen.getByRole('button', { name: 'Copy link' }));

        expect(writeText).toHaveBeenLastCalledWith(LINK);
        expect(writeText).toHaveBeenCalledTimes(2);
        await screen.findByText('Copied');
        expect(toast.success).toHaveBeenLastCalledWith('Link copied.');
    });

    it('says to press and hold when the clipboard refuses', async () => {
        writeText.mockImplementation(() => Promise.reject(new Error('denied')));
        render(<HandoffExtrasList extras={base.extras} />);

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Copy link' }));
        });

        expect(toast.error).toHaveBeenCalledWith(
            'Couldn’t copy that. Press and hold the text instead.',
        );
        expect(screen.queryByText('Copied')).not.toBeInTheDocument();
    });
});
