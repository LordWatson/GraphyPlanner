import { Form, Link, router } from '@inertiajs/react';
import { Clapperboard, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateRange } from '@/lib/dates';
import { index } from '@/routes/clients';
import { store as storeCampaign } from '@/routes/clients/campaigns';
import {
    destroy as destroyCampaign,
    show as showCampaign,
} from '@/routes/campaigns';
import type { ClientPageProps } from '@/components/clients/client-layout';
import {
    ClientLayout,
    TabHeading,
    TabPanel,
} from '@/components/clients/client-layout';
import type { Paginated } from '@/components/pagination';
import { Pagination } from '@/components/pagination';

type CampaignData = {
    id: number;
    name: string;
    status: string;
    start_date: string | null;
    end_date: string | null;
    goal: string | null;
    notes: string | null;
    can: { update: boolean; delete: boolean };
};

/**
 * Step I-2.4 — Campaigns tab (`clients.campaigns.index`), paginated.
 */
export default function ClientCampaignsTab({
    campaigns: campaignPage,
    ...page
}: ClientPageProps & {
    campaigns: Paginated<CampaignData>;
}) {
    const { client, can } = page;
    const campaigns = campaignPage.data;

    return (
        <ClientLayout {...page}>
            <TabPanel>
                <TabHeading
                    icon={Clapperboard}
                    title="Campaigns"
                    description="Marketing campaigns for this client"
                />

                <>
                    <>
                        {can.createCampaign && (
                            <Form
                                {...storeCampaign.form(client.id)}
                                resetOnSuccess
                                className="border-border bg-card/50 grid grid-cols-2 gap-3 rounded-2xl border border-dashed p-4 shadow-sm sm:grid-cols-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-1">
                                            <Label htmlFor="name">Name</Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                required
                                            />
                                            <InputError message={errors.name} />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="start_date">
                                                Start date
                                            </Label>
                                            <Input
                                                id="start_date"
                                                name="start_date"
                                                type="date"
                                            />
                                            <InputError
                                                message={errors.start_date}
                                            />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="end_date">
                                                End date
                                            </Label>
                                            <Input
                                                id="end_date"
                                                name="end_date"
                                                type="date"
                                            />
                                            <InputError
                                                message={errors.end_date}
                                            />
                                        </div>
                                        <div className="col-span-full grid gap-1">
                                            <Label htmlFor="goal">Goal</Label>
                                            <textarea
                                                id="goal"
                                                name="goal"
                                                rows={3}
                                                className="border-input dark:bg-input/30 focus-visible:border-ring focus-visible:ring-ring/50 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                                            />
                                            <InputError message={errors.goal} />
                                        </div>
                                        <div className="col-span-full">
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                Add campaign
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        )}

                        {campaigns.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No campaigns yet.
                            </p>
                        ) : (
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                {campaigns.map((campaign) => (
                                    <div
                                        key={campaign.id}
                                        className="border-border bg-card hover:border-primary/30 hover:bg-muted/40 flex items-center justify-between gap-3 rounded-xl border p-3 shadow-sm transition-colors"
                                    >
                                        <Link
                                            href={showCampaign(campaign.id)}
                                            className="flex flex-1 items-center gap-3"
                                        >
                                            <span className="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-full">
                                                <Clapperboard className="size-4" />
                                            </span>
                                            <div className="flex flex-col gap-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-sm font-medium hover:underline">
                                                        {campaign.name}
                                                    </span>
                                                    <StatusPill
                                                        kind="campaign"
                                                        value={campaign.status}
                                                    />
                                                </div>
                                                <span className="text-muted-foreground text-xs">
                                                    {formatDateRange(
                                                        campaign.start_date,
                                                        campaign.end_date,
                                                    )}
                                                    {campaign.goal
                                                        ? ` · ${campaign.goal}`
                                                        : ''}
                                                </span>
                                            </div>
                                        </Link>
                                        <div className="flex items-center gap-2">
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="outline"
                                            >
                                                <Link
                                                    href={showCampaign(
                                                        campaign.id,
                                                    )}
                                                >
                                                    View
                                                </Link>
                                            </Button>
                                            {campaign.can.delete && (
                                                <Form
                                                    {...destroyCampaign.form(
                                                        campaign.id,
                                                    )}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            <Trash2 />
                                                            Remove
                                                        </Button>
                                                    )}
                                                </Form>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </>
                </>
            </TabPanel>
            <Pagination
                page={campaignPage}
                label="campaigns"
                onPageChange={(page) =>
                    router.reload({ data: { page }, only: ['campaigns'] })
                }
            />
        </ClientLayout>
    );
}

ClientCampaignsTab.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }],
};
