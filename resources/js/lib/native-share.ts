import { Directory, Filesystem } from '@capacitor/filesystem';
import { Share } from '@capacitor/share';

/**
 * Native shell (Capacitor) — the share sheet for a file. An Android WebView has no
 * `navigator.share`, so the Story handoff would fall back to "Save media"; here the file is
 * written to the app's cache and handed to the OS share sheet instead. Loaded only in the shell.
 */
export async function shareFileNatively(
    file: File,
): Promise<'shared' | 'cancelled' | 'failed'> {
    try {
        const dataUrl = await new Promise<string>((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result as string);
            reader.onerror = () => reject(reader.error);
            reader.readAsDataURL(file);
        });
        const { uri } = await Filesystem.writeFile({
            path: file.name || 'story',
            data: dataUrl.slice(dataUrl.indexOf(',') + 1),
            directory: Directory.Cache,
        });

        await Share.share({ files: [uri] });

        return 'shared';
    } catch (error) {
        return /cancel/i.test(String((error as Error)?.message))
            ? 'cancelled'
            : 'failed';
    }
}
