import { Music2, Search, X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { http } from '@/lib/http';
import { cn } from '@/lib/utils';
import { musicSearch as musicSearchRoute } from '@/routes/posts';

type MusicTrackResult = { id: string; name: string; artist: string | null };

/**
 * Step 1.8.4 — live music/sound search, only for Instagram/TikTok targets (the only platforms with
 * a real vendor lookup in `SearchMusicAction`). Writes `{id, name}` into hidden `music[...]` inputs.
 */
export function MusicField({
    postId,
    clientId,
    platforms,
    instagramAccountId,
    musicId,
    musicName,
    onChange,
}: {
    postId: number;
    clientId: number;
    platforms: string[];
    instagramAccountId: number | null;
    musicId: string;
    musicName: string;
    onChange: (track: { id: string; name: string }) => void;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<MusicTrackResult[]>([]);
    const [searching, setSearching] = useState(false);
    const [error, setError] = useState<string | null>(null);
    // Step 1.9.5 — a distinguishable error_code (e.g. "meta_not_connected") lets this render an
    // actionable "Connect Instagram" link instead of bare error text.
    const [errorCode, setErrorCode] = useState<string | null>(null);
    const [platform, setPlatform] = useState<string | null>(null);

    const activePlatform =
        platform && platforms.includes(platform)
            ? platform
            : (platforms[0] ?? null);

    const search = async () => {
        if (!activePlatform || query.trim() === '') {
            return;
        }

        setSearching(true);
        setError(null);
        setErrorCode(null);

        const result = await http.get<{
            tracks?: MusicTrackResult[];
            error?: string;
            error_code?: string | null;
        }>(
            musicSearchRoute.url(postId, {
                query: { query, platform: activePlatform },
            }),
        );

        setSearching(false);

        if (!result.ok) {
            setError('Unable to search music right now.');
            setResults([]);

            return;
        }

        if (result.data.error) {
            setError(result.data.error);
            setErrorCode(result.data.error_code ?? null);
            setResults([]);

            return;
        }

        setResults(result.data.tracks ?? []);
    };

    const hidden = (
        <>
            <input type="hidden" name="music[id]" value={musicId} />
            <input type="hidden" name="music[name]" value={musicName} />
        </>
    );

    if (platforms.length === 0) {
        return (
            <>
                <p className="text-muted-foreground border-border rounded-lg border border-dashed px-3 py-2.5 text-xs">
                    Add an Instagram or TikTok channel to pick a sound.
                </p>
                {hidden}
            </>
        );
    }

    return (
        <div className="grid gap-2">
            {musicName ? (
                <div className="border-border bg-muted/40 flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                    <Music2 className="text-primary size-4 shrink-0" />
                    <span className="min-w-0 flex-1 truncate">{musicName}</span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        aria-label="Remove sound"
                        onClick={() => onChange({ id: '', name: '' })}
                    >
                        <X className="size-3.5" />
                    </Button>
                </div>
            ) : (
                <>
                    {platforms.length > 1 && (
                        <div className="flex gap-1.5">
                            {platforms.map((p) => (
                                <button
                                    key={p}
                                    type="button"
                                    onClick={() => setPlatform(p)}
                                    className={cn(
                                        'rounded-full border px-2.5 py-0.5 text-xs transition-colors',
                                        activePlatform === p
                                            ? 'bg-ink text-ink-foreground border-transparent'
                                            : 'border-border text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {p === 'instagram' ? 'Instagram' : 'TikTok'}
                                </button>
                            ))}
                        </div>
                    )}
                    <div className="relative">
                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                        <Input
                            id="music_search"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    void search();
                                }
                            }}
                            placeholder={
                                searching
                                    ? 'Searching…'
                                    : 'Search a track, press Enter'
                            }
                            className="pl-9"
                        />
                    </div>
                </>
            )}

            {error && (
                <p className="text-destructive text-xs">
                    {error}
                    {errorCode === 'meta_not_connected' &&
                        instagramAccountId && (
                            <>
                                {' '}
                                <a
                                    href={`/clients/${clientId}/social-accounts/${instagramAccountId}/facebook/connect`}
                                    className="underline"
                                >
                                    Connect Instagram for music search
                                </a>
                            </>
                        )}
                </p>
            )}

            {results.length > 0 && (
                <ul className="border-border bg-popover grid max-h-56 gap-0.5 overflow-y-auto rounded-lg border p-1">
                    {results.map((track) => (
                        <li key={track.id}>
                            <button
                                type="button"
                                onClick={() => {
                                    onChange({
                                        id: track.id,
                                        name: track.name,
                                    });
                                    setResults([]);
                                    setQuery('');
                                }}
                                className="hover:bg-muted flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm"
                            >
                                <Music2 className="text-muted-foreground size-3.5 shrink-0" />
                                <span className="min-w-0 flex-1 truncate">
                                    {track.name}
                                    {track.artist && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            — {track.artist}
                                        </span>
                                    )}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {hidden}
        </div>
    );
}
