import { useEffect, useState } from 'react';
import type {
    LibraryPage,
    LibraryScope,
} from '@/components/post-editor/asset-library/types';
import { http } from '@/lib/http';
import { index as assetIndex } from '@/routes/clients/assets';

/**
 * The asset library's server-side browse state: debounced search, type and campaign scope
 * filters, page, and the current page of results. Loads only while `open`; every filter change
 * goes back to page 1, and a newer request cancels the one in flight.
 */
export function useAssetLibrary({
    open,
    clientId,
    campaignId,
    imagesOnly,
    defaultScope,
}: {
    open: boolean;
    clientId: number;
    campaignId?: number;
    imagesOnly: boolean;
    defaultScope: LibraryScope;
}) {
    const [search, setSearch] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [type, setTypeState] = useState(imagesOnly ? 'image' : 'all');
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<LibraryPage | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [reloadKey, setReloadKey] = useState(0);
    // Step C-4.1: a campaign post's library opens on its campaign's kit first.
    const [scope, setScopeState] = useState<LibraryScope>(
        campaignId !== undefined ? defaultScope : 'all',
    );

    useEffect(() => {
        const timer = window.setTimeout(() => {
            setDebouncedSearch(search.trim());
            setPage(1);
        }, 300);

        return () => window.clearTimeout(timer);
    }, [search]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();
        setLoading(true);
        setError(null);

        void http
            .get<LibraryPage>(
                assetIndex.url(clientId, {
                    query: {
                        search: debouncedSearch || undefined,
                        type: type === 'all' ? undefined : type,
                        page,
                        campaign:
                            campaignId !== undefined && scope === 'campaign'
                                ? campaignId
                                : undefined,
                    },
                }),
                { signal: controller.signal },
            )
            .then((response) => {
                if (response.ok) {
                    setResult(response.data);
                } else if (!response.aborted) {
                    setError('Unable to load assets right now.');
                }

                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [
        open,
        clientId,
        debouncedSearch,
        type,
        page,
        reloadKey,
        scope,
        campaignId,
    ]);

    return {
        search,
        setSearch,
        type,
        setType: (next: string) => {
            setTypeState(next);
            setPage(1);
        },
        scope,
        setScope: (next: LibraryScope) => {
            setScopeState(next);
            setPage(1);
        },
        setPage,
        result,
        loading,
        error,
        filtered: debouncedSearch !== '' || type !== 'all',
        reload: () => setReloadKey((key) => key + 1),
    };
}
