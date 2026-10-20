import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { StudioGeneration } from '@/components/image-studio/types';
import { http } from '@/lib/http';
import { show as showGeneration } from '@/routes/ai-image-generations';

const POLL_MS = 2000;

/**
 * Keeps unsettled image sets fresh while their tiles render on the queue: every 2s, each pending
 * set's lean status endpoint (`ai-image-generations.show`) is fetched and merged in, so tiles fill
 * in as each job finishes without reloading the page's other props. Pauses while the tab is
 * hidden, stops by itself once every set has settled, then reloads `imageStudio` once so the
 * quota and history line up.
 */
export function useGenerationPolling(generations: StudioGeneration[]) {
    const [live, setLive] = useState<Record<number, StudioGeneration>>({});
    const wasPolling = useRef(false);

    // Fresh props win over anything polled earlier.
    useEffect(() => {
        setLive({});
    }, [generations]);

    const merged = useMemo(
        () =>
            generations.map((generation) => live[generation.id] ?? generation),
        [generations, live],
    );

    const pendingIds = merged
        .filter((generation) => !generation.settled)
        .map((generation) => generation.id);
    const pendingKey = pendingIds.join(',');

    useEffect(() => {
        if (pendingIds.length === 0) {
            if (wasPolling.current) {
                wasPolling.current = false;
                router.reload({ only: ['imageStudio'] });
            }

            return;
        }

        wasPolling.current = true;
        const controller = new AbortController();

        const tick = async () => {
            if (document.visibilityState === 'hidden') {
                return;
            }

            await Promise.all(
                pendingIds.map(async (id) => {
                    // Aborted or offline: the next tick tries again.
                    const result = await http.get<{
                        generation: StudioGeneration;
                    }>(showGeneration.url(id), { signal: controller.signal });

                    if (result.ok) {
                        const { generation } = result.data;
                        setLive((current) => ({
                            ...current,
                            [generation.id]: generation,
                        }));
                    }
                }),
            );
        };

        const timer = window.setInterval(tick, POLL_MS);

        return () => {
            window.clearInterval(timer);
            controller.abort();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pendingKey]);

    return merged;
}
