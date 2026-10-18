import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import {
    CampaignCoreFields,
    CampaignSettingsFields,
    NONE,
    toPayload,
} from '@/components/campaigns/campaign-form-fields';
import type {
    CampaignFormData,
    CampaignFormOptions,
} from '@/components/campaigns/campaign-form-fields';
import type { StaffCampaign } from '@/components/campaigns/types';
import { Page, PageHeader, Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    index as campaignsIndex,
    show as showCampaign,
    update as updateCampaign,
} from '@/routes/campaigns';

/**
 * Campaign settings (Steps C-0.2, C-4.2 defaults, C-8.1 billing). Status is not edited here — it
 * moves through the workspace header's actions so every change is checked and logged.
 */
export default function CampaignEdit({
    campaign,
    options,
}: {
    campaign: StaffCampaign;
    options: CampaignFormOptions;
}) {
    const form = useForm<CampaignFormData>({
        name: campaign.name,
        code: campaign.code ?? '',
        owner_id: campaign.owner ? String(campaign.owner.id) : NONE,
        objective: campaign.objective ?? NONE,
        colour: campaign.colour ?? '',
        platforms: campaign.platforms,
        start_date: campaign.start_date ?? '',
        end_date: campaign.end_date ?? '',
        goal: campaign.goal ?? '',
        notes: campaign.notes ?? '',
        hashtags: campaign.hashtags,
        default_link_url: campaign.default_link_url ?? '',
        utm_campaign: campaign.utm_campaign ?? '',
        billing_type: campaign.billing?.billing_type ?? NONE,
        fee_amount: campaign.billing?.fee_amount ?? '',
    });
    const canBill = campaign.billing !== null;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => {
            const payload = toPayload(data);

            if (!canBill) {
                delete payload.billing_type;
                delete payload.fee_amount;
            }

            return payload;
        });
        form.put(updateCampaign.url(campaign.id));
    };

    return (
        <>
            <Head title={`Edit ${campaign.name}`} />
            <Page width="narrow">
                <Link
                    href={showCampaign(campaign.id)}
                    className="text-muted-foreground hover:text-foreground -mb-4 inline-flex items-center gap-1.5 self-start text-sm"
                >
                    <ArrowLeft className="size-4" />
                    Back to {campaign.name}
                </Link>
                <PageHeader
                    eyebrow={`Campaign — ${campaign.client.name}`}
                    title={
                        <>
                            Campaign <em>details</em>.
                        </>
                    }
                    description="Who owns it, when it runs, and the defaults every post in it gets."
                />

                <form onSubmit={submit} className="flex flex-col gap-5">
                    <Panel eyebrow="A — The basics" title="What and when">
                        <CampaignCoreFields form={form} options={options} />
                    </Panel>
                    <Panel
                        eyebrow="B — Defaults & notes"
                        title="What every post gets"
                    >
                        <CampaignSettingsFields
                            form={form}
                            options={options}
                            canBill={canBill}
                        />
                    </Panel>
                    <div className="flex items-center justify-end gap-2">
                        <Button asChild variant="ghost">
                            <Link href={showCampaign(campaign.id)}>Cancel</Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Save changes
                        </Button>
                    </div>
                </form>
            </Page>
        </>
    );
}

CampaignEdit.layout = {
    breadcrumbs: [{ title: 'Campaigns', href: campaignsIndex() }],
};
