import { CalendarDays } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/clients';
import dailyBriefs from '@/routes/clients/daily-briefs';
import type { ClientPageProps } from '@/components/clients/client-layout';
import {
    ClientLayout,
    TabHeading,
    TabPanel,
} from '@/components/clients/client-layout';

/**
 * Step I-2.4 — Daily Briefs tab (`clients.briefs`); the list comes from the `clients.daily-briefs.index` JSON endpoint.
 */
export default function ClientBriefsTab({ ...page }: ClientPageProps) {
    const { client } = page;
    const [briefsLoading, setBriefsLoading] = useState(false);
    const [briefsError, setBriefsError] = useState<string | null>(null);
    const [briefs, setBriefs] = useState<
        {
            id: number;
            brief_date: string;
            trends: { title: string; summary: string }[];
            ideas: { title: string; description: string }[];
        }[]
    >([]);

    const fetchDailyBriefs = async () => {
        setBriefsError(null);
        setBriefsLoading(true);

        try {
            const res = await fetch(dailyBriefs.index.url(client.id), {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const data = await res.json();

            if (!res.ok)
                throw new Error(
                    typeof data?.message === 'string'
                        ? data.message
                        : 'Unable to fetch daily briefs.',
                );

            setBriefs(Array.isArray(data?.briefs) ? data.briefs : []);
        } catch (e) {
            setBriefsError(
                e instanceof Error
                    ? e.message
                    : 'Unable to fetch daily briefs.',
            );
        } finally {
            setBriefsLoading(false);
        }
    };

    useEffect(() => {
        void fetchDailyBriefs();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <ClientLayout {...page}>
            <TabPanel>
                <TabHeading
                    icon={CalendarDays}
                    title="Daily Briefs"
                    description="Nightly AI-generated trends and post ideas for this client"
                />

                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        size="sm"
                        onClick={fetchDailyBriefs}
                        disabled={briefsLoading}
                    >
                        {briefsLoading ? 'Loading…' : 'Load briefs'}
                    </Button>
                    {briefsError && (
                        <span className="text-destructive text-xs">
                            {briefsError}
                        </span>
                    )}
                </div>

                {briefs.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No daily briefs yet. Click "Load briefs".
                    </p>
                ) : (
                    <div className="grid gap-3">
                        {briefs.map((brief) => (
                            <div
                                key={brief.id}
                                className="border-border bg-card rounded-2xl border p-4 shadow-sm"
                            >
                                <div className="mb-2 text-[15px] font-semibold">
                                    {brief.brief_date}
                                </div>
                                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                                    <div className="grid gap-2">
                                        <h4 className="text-muted-foreground text-xs font-medium">
                                            Trends
                                        </h4>
                                        {brief.trends.map((trend, i) => (
                                            <div
                                                key={i}
                                                className="border-border bg-muted/20 rounded-xl border p-2 shadow-sm"
                                            >
                                                <div className="text-sm font-medium">
                                                    {trend.title}
                                                </div>
                                                <p className="text-muted-foreground text-xs">
                                                    {trend.summary}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                    <div className="grid gap-2">
                                        <h4 className="text-muted-foreground text-xs font-medium">
                                            Ideas
                                        </h4>
                                        {brief.ideas.map((idea, i) => (
                                            <div
                                                key={i}
                                                className="border-border bg-muted/20 rounded-xl border p-2 shadow-sm"
                                            >
                                                <div className="text-sm font-medium">
                                                    {idea.title}
                                                </div>
                                                <p className="text-muted-foreground text-xs">
                                                    {idea.description}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </TabPanel>
        </ClientLayout>
    );
}

ClientBriefsTab.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }],
};
