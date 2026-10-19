import { Deferred, router } from '@inertiajs/react';
import { Loader2, TrendingUp } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/clients';
import type {
    AiIdeaData,
    AiInsight,
    AiTrendData,
} from '@/components/clients/ai-insights';
import {
    AiInsightCard,
    requestAiInsights,
} from '@/components/clients/ai-insights';
import type { ClientPageProps } from '@/components/clients/client-layout';
import { Pagination } from '@/components/pagination';
import {
    ClientLayout,
    TabHeading,
    TabPanel,
    usePlatformOptions,
} from '@/components/clients/client-layout';
import { platformIcon } from '@/components/platform-icon';

/**
 * Step I-2.4 — Trends & Ideas tab (`clients.ai.index`). Trends and ideas are deferred props.
 */
export default function ClientAiTab({
    pillars,
    campaignOptions,
    trends,
    ideas,
    ...page
}: ClientPageProps & {
    pillars: string[];
    campaignOptions: { id: number; name: string }[];
    trends?: AiTrendData[];
    ideas?: AiIdeaData[];
}) {
    const platformOptions = usePlatformOptions();
    const { client } = page;
    const ai = { pillars, campaigns: campaignOptions, trends, ideas };
    const [aiLoading, setAiLoading] = useState(false);
    const [aiError, setAiError] = useState<string | null>(null);
    const [aiPlatforms, setAiPlatforms] = useState<string[]>(['instagram']);
    const [aiPillarFilter, setAiPillarFilter] = useState<string>('');
    const [aiCampaignFilter, setAiCampaignFilter] = useState<string>('');
    const [aiTypeFilter, setAiTypeFilter] = useState<'all' | 'trend' | 'idea'>(
        'all',
    );
    const [aiListPlatformFilter, setAiListPlatformFilter] =
        useState<string>('');
    // Trends/ideas are persisted server-side (AiController) and arrive as deferred props, so the
    // list survives a refresh — Refresh triggers a new AI call, then reloads them.
    const trendItems = ai?.trends ?? [];
    const ideaItems = ai?.ideas ?? [];

    const fetchTrendsAndIdeas = async () => {
        setAiError(null);
        setAiLoading(true);

        try {
            await requestAiInsights(client.id, aiPlatforms);
            // Both endpoints already saved their results — reload the deferred lists.
            router.reload({ only: ['trends', 'ideas', 'stats'] });
        } catch (e) {
            setAiError(
                e instanceof Error ? e.message : 'Unable to fetch AI data.',
            );
        } finally {
            setAiLoading(false);
        }
    };

    const filteredIdeas = ideaItems.filter((idea) => {
        const text = `${idea.title} ${idea.description ?? ''}`.toLowerCase();

        if (aiPillarFilter && !text.includes(aiPillarFilter.toLowerCase())) {
            return false;
        }

        if (
            aiCampaignFilter &&
            !text.includes(aiCampaignFilter.toLowerCase())
        ) {
            return false;
        }

        return true;
    });

    // Merge trends and ideas into a single list, tagged with their type, so they can be
    // shown together (newest first) in one paginated list instead of two side-by-side lists.
    const AI_ITEMS_PER_PAGE = 5;
    const combinedAiItems: AiInsight[] = [
        ...trendItems.map((trend) => ({ type: 'trend' as const, item: trend })),
        ...filteredIdeas.map((idea) => ({ type: 'idea' as const, item: idea })),
    ].filter(({ type, item }) => {
        if (aiTypeFilter !== 'all' && type !== aiTypeFilter) {
            return false;
        }

        if (
            aiListPlatformFilter &&
            !item.platforms.includes(aiListPlatformFilter)
        ) {
            return false;
        }

        return true;
    });
    const [aiPage, setAiPage] = useState(1);
    const aiTotalPages = Math.max(
        1,
        Math.ceil(combinedAiItems.length / AI_ITEMS_PER_PAGE),
    );
    const aiCurrentPage = Math.min(aiPage, aiTotalPages);
    const paginatedAiItems = combinedAiItems.slice(
        (aiCurrentPage - 1) * AI_ITEMS_PER_PAGE,
        aiCurrentPage * AI_ITEMS_PER_PAGE,
    );

    useEffect(() => {
        setAiPage(1);
    }, [
        trendItems.length,
        filteredIdeas.length,
        aiTypeFilter,
        aiListPlatformFilter,
    ]);
    return (
        <ClientLayout {...page}>
            <TabPanel>
                <Deferred
                    data={['trends', 'ideas']}
                    fallback={
                        <div className="grid gap-3">
                            <Skeleton className="h-10 rounded-xl" />
                            <Skeleton className="h-24 rounded-xl" />
                            <Skeleton className="h-24 rounded-xl" />
                        </div>
                    }
                >
                    <>
                        <TabHeading
                            icon={TrendingUp}
                            title="Trends & Ideas"
                            description="AI-discovered trends and fresh post ideas for this client"
                        />

                        <div className="flex flex-wrap items-center gap-2">
                            {platformOptions.map((platform) => {
                                const selected = aiPlatforms.includes(
                                    platform.value,
                                );
                                const PlatformIcon = platformIcon(
                                    platform.value,
                                );

                                return (
                                    <button
                                        key={platform.value}
                                        type="button"
                                        onClick={() =>
                                            setAiPlatforms((current) =>
                                                selected
                                                    ? current.filter(
                                                          (v) =>
                                                              v !==
                                                              platform.value,
                                                      )
                                                    : [
                                                          ...current,
                                                          platform.value,
                                                      ],
                                            )
                                        }
                                        className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors ${
                                            selected
                                                ? 'border-primary bg-primary/10 text-primary'
                                                : 'border-border bg-muted/50 text-foreground'
                                        }`}
                                    >
                                        <PlatformIcon className="size-3.5" />
                                        {platform.label}
                                    </button>
                                );
                            })}
                            <Button
                                type="button"
                                size="sm"
                                onClick={fetchTrendsAndIdeas}
                                disabled={aiLoading}
                            >
                                {aiLoading ? 'Loading…' : 'Refresh'}
                            </Button>
                            {aiError && (
                                <span className="text-destructive text-xs">
                                    {aiError}
                                </span>
                            )}
                        </div>

                        <div className="flex flex-wrap gap-3">
                            <div className="grid gap-1">
                                <Label>Filter by type</Label>
                                <div className="border-border flex items-center gap-1 rounded-md border p-0.5">
                                    {(
                                        [
                                            { value: 'all', label: 'All' },
                                            {
                                                value: 'trend',
                                                label: 'Trends',
                                            },
                                            {
                                                value: 'idea',
                                                label: 'Ideas',
                                            },
                                        ] as const
                                    ).map((option) => (
                                        <button
                                            key={option.value}
                                            type="button"
                                            onClick={() =>
                                                setAiTypeFilter(option.value)
                                            }
                                            className={`rounded-sm px-2.5 py-1 text-xs transition-colors ${
                                                aiTypeFilter === option.value
                                                    ? 'bg-primary text-primary-foreground'
                                                    : 'text-muted-foreground hover:text-foreground'
                                            }`}
                                        >
                                            {option.label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="ai-list-platform-filter">
                                    Filter by channel
                                </Label>
                                <Select
                                    value={aiListPlatformFilter || '__all'}
                                    onValueChange={(v) =>
                                        setAiListPlatformFilter(
                                            v === '__all' ? '' : v,
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        id="ai-list-platform-filter"
                                        className="w-48"
                                    >
                                        <SelectValue placeholder="All channels" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all">
                                            All channels
                                        </SelectItem>
                                        {platformOptions.map((platform) => (
                                            <SelectItem
                                                key={platform.value}
                                                value={platform.value}
                                            >
                                                {platform.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="ai-pillar-filter">
                                    Filter ideas by pillar
                                </Label>
                                <Select
                                    value={aiPillarFilter || '__all'}
                                    onValueChange={(v) =>
                                        setAiPillarFilter(
                                            v === '__all' ? '' : v,
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        id="ai-pillar-filter"
                                        className="w-48"
                                    >
                                        <SelectValue placeholder="All pillars" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all">
                                            All pillars
                                        </SelectItem>
                                        {(ai?.pillars ?? []).map((pillar) => (
                                            <SelectItem
                                                key={pillar}
                                                value={pillar}
                                            >
                                                {pillar}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="ai-campaign-filter">
                                    Filter ideas by campaign
                                </Label>
                                <Select
                                    value={aiCampaignFilter || '__all'}
                                    onValueChange={(v) =>
                                        setAiCampaignFilter(
                                            v === '__all' ? '' : v,
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        id="ai-campaign-filter"
                                        className="w-48"
                                    >
                                        <SelectValue placeholder="All campaigns" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all">
                                            All campaigns
                                        </SelectItem>
                                        {(ai?.campaigns ?? []).map(
                                            (campaign) => (
                                                <SelectItem
                                                    key={campaign.id}
                                                    value={campaign.name}
                                                >
                                                    {campaign.name}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="relative grid gap-2">
                            {aiLoading && (
                                <div className="bg-background/70 text-muted-foreground absolute inset-0 z-10 flex items-center justify-center gap-2 rounded-md text-sm backdrop-blur-sm">
                                    <Loader2 className="size-4 animate-spin" />
                                    Loading trends &amp; ideas…
                                </div>
                            )}

                            {combinedAiItems.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    No trends or ideas saved yet, or none match
                                    the current filters.
                                </p>
                            ) : (
                                paginatedAiItems.map((insight) => (
                                    <AiInsightCard
                                        key={`${insight.type}-${insight.item.id}`}
                                        insight={insight}
                                    />
                                ))
                            )}

                            <Pagination
                                className="pt-2"
                                label="trends & ideas"
                                page={{
                                    current_page: aiCurrentPage,
                                    last_page: aiTotalPages,
                                    total: combinedAiItems.length,
                                    from:
                                        (aiCurrentPage - 1) *
                                            AI_ITEMS_PER_PAGE +
                                        1,
                                    to: Math.min(
                                        aiCurrentPage * AI_ITEMS_PER_PAGE,
                                        combinedAiItems.length,
                                    ),
                                }}
                                onPageChange={setAiPage}
                            />
                        </div>
                    </>
                </Deferred>
            </TabPanel>
        </ClientLayout>
    );
}

ClientAiTab.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }],
};
