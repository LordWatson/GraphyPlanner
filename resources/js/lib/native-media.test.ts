import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
    showActions: vi.fn(),
    takePhoto: vi.fn(),
    recordVideo: vi.fn(),
    chooseFromGallery: vi.fn(),
    readFile: vi.fn(),
}));

vi.mock('@capacitor/action-sheet', () => ({
    ActionSheet: { showActions: mocks.showActions },
}));
vi.mock('@capacitor/camera', () => ({
    Camera: {
        takePhoto: mocks.takePhoto,
        recordVideo: mocks.recordVideo,
        chooseFromGallery: mocks.chooseFromGallery,
    },
    MediaType: { Photo: 0, Video: 1 },
    MediaTypeSelection: { Photo: 0, Video: 1, All: 2 },
}));
vi.mock('@capacitor/filesystem', () => ({
    Filesystem: { readFile: mocks.readFile },
}));

import {
    installNativeFilePicker,
    mediaKinds,
    pickNativeMedia,
} from '@/lib/native-media';

const jpeg = () => new Blob(['x'], { type: 'image/jpeg' });

beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => new Response(jpeg())),
    );
});

describe('mediaKinds', () => {
    it('recognises media inputs and leaves the rest to the browser', () => {
        expect(mediaKinds('image/*,video/*')).toEqual({
            image: true,
            video: true,
        });
        expect(mediaKinds('image/png,image/jpeg')).toEqual({
            image: true,
            video: false,
        });
        expect(mediaKinds('.csv,text/csv,text/plain')).toBeNull();
        expect(mediaKinds('application/pdf')).toBeNull();
        expect(mediaKinds('')).toBeNull();
    });
});

describe('pickNativeMedia', () => {
    it('returns a camera photo as a File', async () => {
        mocks.showActions.mockResolvedValue({ index: 0 });
        mocks.takePhoto.mockResolvedValue({
            type: 0,
            uri: 'file:///cache/shot.jpg',
            webPath: 'https://app.test/_capacitor_file_/cache/shot.jpg',
        });

        const files = await pickNativeMedia(
            { image: true, video: true },
            false,
        );

        expect(files).toHaveLength(1);
        expect(files[0]).toBeInstanceOf(File);
        expect(files[0].name).toBe('shot.jpg');
        expect(files[0].type).toBe('image/jpeg');
    });

    it('offers only what the input accepts and picks several from the library', async () => {
        mocks.showActions.mockResolvedValue({ index: 1 });
        mocks.chooseFromGallery.mockResolvedValue({
            results: [
                {
                    type: 0,
                    uri: 'file:///a.jpg',
                    webPath: 'https://app.test/a',
                },
                {
                    type: 0,
                    uri: 'file:///b.jpg',
                    webPath: 'https://app.test/b',
                },
            ],
        });

        const files = await pickNativeMedia(
            { image: true, video: false },
            true,
        );

        expect(
            mocks.showActions.mock.calls[0][0].options.map(
                (option: { title: string }) => option.title,
            ),
        ).toEqual(['Take photo', 'Choose from library', 'Cancel']);
        expect(mocks.chooseFromGallery).toHaveBeenCalledWith({
            mediaType: 0,
            allowMultipleSelection: true,
        });
        expect(files.map((file) => file.name)).toEqual(['a.jpg', 'b.jpg']);
    });

    it('reads through the bridge when the WebView cannot serve the file', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => new Response(null, { status: 404 })),
        );
        mocks.showActions.mockResolvedValue({ index: 1 });
        mocks.recordVideo.mockResolvedValue({
            type: 1,
            uri: 'file:///cache/clip.mp4',
            webPath: 'https://app.test/_capacitor_file_/cache/clip.mp4',
        });
        mocks.readFile.mockResolvedValue({ data: 'dg==' });

        const files = await pickNativeMedia(
            { image: true, video: true },
            false,
        );

        expect(mocks.readFile).toHaveBeenCalledWith({
            path: 'file:///cache/clip.mp4',
        });
        expect(files[0].name).toBe('clip.mp4');
        expect(files[0].type).toBe('video/mp4');
    });

    it('returns nothing when the user cancels or the camera is refused', async () => {
        mocks.showActions.mockResolvedValue({ index: 2 });
        expect(
            await pickNativeMedia({ image: true, video: false }, false),
        ).toEqual([]);

        mocks.showActions.mockResolvedValue({ index: 0 });
        mocks.takePhoto.mockRejectedValue(new Error('User denied access'));
        expect(
            await pickNativeMedia({ image: true, video: false }, false),
        ).toEqual([]);
    });
});

describe('installNativeFilePicker', () => {
    it('takes over a media input and leaves a CSV input to the browser', () => {
        mocks.showActions.mockResolvedValue({ index: 99 });
        installNativeFilePicker();

        const media = document.createElement('input');
        media.type = 'file';
        media.accept = 'image/*,video/*';
        const csv = document.createElement('input');
        csv.type = 'file';
        csv.accept = '.csv,text/csv';
        document.body.append(media, csv);

        const mediaClick = new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
        });
        media.dispatchEvent(mediaClick);
        const csvClick = new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
        });
        csv.dispatchEvent(csvClick);

        expect(mediaClick.defaultPrevented).toBe(true);
        expect(csvClick.defaultPrevented).toBe(false);
        expect(mocks.showActions).toHaveBeenCalledTimes(1);
    });
});
