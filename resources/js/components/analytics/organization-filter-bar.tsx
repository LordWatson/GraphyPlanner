import { Building2, Layers, Megaphone, Share2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { MultiSelectFilter, RangePicker } from './filter-controls';
import type { OrgFilterOptions, OrgFilterState, SectionKey } from './types';
import { sectionKeys, sectionLabels } from './types';

/**
 * Step 17.4 — the studio analytics filter row: range (teal pill), then clients, campaigns,
 * platforms and sections. Controlled, like the platform console's bar: the page re-queries, the
 * report builder just updates its form. Campaign choices follow the client selection. Sections the
 * viewer can't see (billing for a Designer) aren't offered.
 */
export function OrganizationFilterBar({
    value,
    onChange,
    options,
    sections = sectionKeys,
    showClients = true,
    className,
}: {
    value: OrgFilterState;
    onChange: (next: OrgFilterState) => void;
    options: OrgFilterOptions;
    /** The sections this viewer may pick from. */
    sections?: SectionKey[];
    /** Hidden on a client's own drill-down. */
    showClients?: boolean;
    className?: string;
}) {
    const update = (next: Partial<OrgFilterState>) =>
        onChange({ ...value, ...next });

    const campaigns = options.campaigns.filter(
        (campaign) =>
            value.client_ids.length === 0 ||
            value.client_ids.includes(campaign.client_id),
    );

    return (
        <div
            className={cn(
                'flex flex-col gap-3 xl:flex-row xl:flex-wrap xl:items-center',
                className,
            )}
        >
            <RangePicker
                range={value.range}
                from={value.from}
                to={value.to}
                onChange={(range) => update(range)}
            />

            <div className="flex flex-wrap items-center gap-2 xl:ml-auto">
                {showClients && (
                    <MultiSelectFilter
                        icon={Building2}
                        label="Clients"
                        allLabel="All clients"
                        noun="clients"
                        options={options.clients.map((client) => ({
                            value: client.value,
                            label: client.label,
                            hint: client.archived ? 'Archived' : undefined,
                        }))}
                        value={value.client_ids}
                        onChange={(client_ids) =>
                            update({
                                client_ids,
                                // Drop campaigns that no longer belong to a selected client.
                                campaign_ids: value.campaign_ids.filter((id) =>
                                    options.campaigns.some(
                                        (campaign) =>
                                            campaign.value === id &&
                                            (client_ids.length === 0 ||
                                                client_ids.includes(
                                                    campaign.client_id,
                                                )),
                                    ),
                                ),
                            })
                        }
                    />
                )}

                <MultiSelectFilter
                    icon={Megaphone}
                    label="Campaigns"
                    allLabel="All campaigns"
                    noun="campaigns"
                    options={campaigns.map((campaign) => ({
                        value: campaign.value,
                        label: campaign.label,
                        hint: showClients ? campaign.client : undefined,
                    }))}
                    value={value.campaign_ids}
                    onChange={(campaign_ids) => update({ campaign_ids })}
                    disabled={campaigns.length === 0}
                />

                <MultiSelectFilter
                    icon={Share2}
                    label="Platforms"
                    allLabel="All platforms"
                    noun="platforms"
                    options={options.platforms}
                    value={value.platforms}
                    onChange={(platforms) => update({ platforms })}
                    disabled={options.platforms.length === 0}
                />

                <MultiSelectFilter
                    icon={Layers}
                    label="Sections"
                    allLabel="All sections"
                    noun="sections"
                    mode="sections"
                    options={sections.map((section) => ({
                        value: section,
                        label: sectionLabels[section],
                    }))}
                    value={value.metrics.filter((metric) =>
                        sections.includes(metric),
                    )}
                    onChange={(metrics) => update({ metrics })}
                />
            </div>
        </div>
    );
}
