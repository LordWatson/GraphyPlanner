import { router } from '@inertiajs/react';
import { Megaphone, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { EmptyState, Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatCurrency } from '@/lib/currency';
import {
    destroy as destroyAdAccount,
    store as storeAdAccount,
    sync as syncAdAccount,
} from '@/routes/clients/ad-accounts';

export type AdAccountRow = {
    id: number;
    name: string;
    platform: string;
    platform_label: string;
    connected: boolean;
    currency: string | null;
    has_data: boolean;
    spend_minor: number | null;
    impressions: number | null;
    clicks: number | null;
    conversions: number | null;
    cpc_minor: number | null;
    cpa_minor: number | null;
    last_error: string | null;
    last_synced_at: string | null;
};

export type AdsData = {
    entitled: boolean;
    window_days: number;
    default_currency: string;
    accounts: AdAccountRow[];
    totals: {
        spend_minor: number | null;
        impressions: number | null;
        clicks: number | null;
        conversions: number | null;
        cpc_minor: number | null;
        cpa_minor: number | null;
        currency: string | null;
    };
    organic: { posts: number; engagement: number | null };
};

/**
 * Step GTM-5.2 — read-only paid-ads reporting, with the organic figures beside it.
 *
 * **Nothing on this panel can spend money.** It reports; there is no budget field and no campaign
 * control, because the contract behind it has no write method (`App\Contracts\AdsSource`).
 *
 * A gap is a gap: a disconnected account, or one the platform wouldn't answer for, shows em dashes
 * and the reason — never a zero, which in a spend report would read as "we spent nothing".
 */
export function AdsPanel({
    clientId,
    ads,
    platforms,
    canManage,
}: {
    clientId: number;
    ads: AdsData;
    platforms: { value: string; label: string }[];
    canManage: boolean;
}) {
    const [platform, setPlatform] = useState(platforms[0]?.value ?? '');
    const [externalId, setExternalId] = useState('');
    const [name, setName] = useState('');

    const money = (minor: number | null, currency: string | null) =>
        minor === null
            ? null
            : formatCurrency(minor / 100, currency ?? ads.default_currency);

    if (!ads.entitled) {
        return (
            <Panel
                eyebrow="Paid"
                title="Ad spend"
                icon={Megaphone}
                description="Report on what the ads did, beside what the posts did."
            >
                <EmptyState
                    compact
                    className="border-0"
                    icon={Megaphone}
                    title="Ads reporting is on an agency plan."
                    description="Spend, results and cost per result for a client's Meta ad accounts, here and in their monthly report. Reporting only — Alima never spends."
                />
            </Panel>
        );
    }

    return (
        <Panel
            eyebrow={`Last ${ads.window_days} days`}
            title="Ad spend"
            icon={Megaphone}
            description="Reporting only: Alima reads these figures and never changes anything at the platform."
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="border-border/70 rounded-xl border p-4">
                    <p className="font-mono text-[10px] tracking-widest uppercase opacity-70">
                        Paid
                    </p>
                    <p className="font-display text-2xl">
                        {money(ads.totals.spend_minor, ads.totals.currency) ?? (
                            <span className="text-muted-foreground">
                                &mdash;
                            </span>
                        )}
                    </p>
                    <p className="text-muted-foreground text-sm">
                        {ads.totals.conversions === null
                            ? 'No results reported'
                            : `${ads.totals.conversions.toLocaleString()} results`}
                        {ads.totals.cpa_minor !== null &&
                            ` · ${money(ads.totals.cpa_minor, ads.totals.currency)} each`}
                    </p>
                </div>
                <div className="border-border/70 rounded-xl border p-4">
                    <p className="font-mono text-[10px] tracking-widest uppercase opacity-70">
                        Organic
                    </p>
                    <p className="font-display text-2xl">
                        {ads.organic.posts.toLocaleString()}
                    </p>
                    <p className="text-muted-foreground text-sm">
                        posts
                        {ads.organic.engagement === null
                            ? ' · interactions not reported'
                            : ` · ${ads.organic.engagement.toLocaleString()} interactions`}
                    </p>
                </div>
            </div>

            {ads.accounts.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    No ad accounts yet. Add one below and the first read happens
                    straight away.
                </p>
            ) : (
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-muted-foreground border-border/70 border-b text-left">
                            <th className="py-2 font-medium">Account</th>
                            <th className="py-2 text-right font-medium">
                                Spend
                            </th>
                            <th className="py-2 text-right font-medium">
                                Clicks
                            </th>
                            <th className="py-2 text-right font-medium">
                                Results
                            </th>
                            <th className="py-2 text-right font-medium">
                                Cost / result
                            </th>
                            {canManage && <th className="py-2" />}
                        </tr>
                    </thead>
                    <tbody>
                        {ads.accounts.map((account) => (
                            <tr
                                key={account.id}
                                className="border-border/40 border-b"
                            >
                                <td className="py-2.5">
                                    {account.name}
                                    <span className="text-muted-foreground ml-2 text-xs">
                                        {account.platform_label}
                                    </span>
                                    {(!account.connected ||
                                        !account.has_data) && (
                                        <span className="text-muted-foreground block text-xs">
                                            {account.last_error ??
                                                'Nothing reported for this window.'}
                                        </span>
                                    )}
                                </td>
                                <Cell
                                    text={money(
                                        account.spend_minor,
                                        account.currency,
                                    )}
                                />
                                <Cell
                                    text={
                                        account.clicks?.toLocaleString() ?? null
                                    }
                                />
                                <Cell
                                    text={
                                        account.conversions?.toLocaleString() ??
                                        null
                                    }
                                />
                                <Cell
                                    text={money(
                                        account.cpa_minor,
                                        account.currency,
                                    )}
                                />
                                {canManage && (
                                    <td className="py-2.5 text-right whitespace-nowrap">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            aria-label={`Refresh ${account.name}`}
                                            onClick={() =>
                                                router.post(
                                                    syncAdAccount({
                                                        client: clientId,
                                                        adAccount: account.id,
                                                    }).url,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <RefreshCw className="size-4" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            aria-label={`Stop reporting on ${account.name}`}
                                            onClick={() =>
                                                router.delete(
                                                    destroyAdAccount({
                                                        client: clientId,
                                                        adAccount: account.id,
                                                    }).url,
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            {canManage && platforms.length > 0 && (
                <div className="flex flex-wrap items-end gap-2">
                    <Select value={platform} onValueChange={setPlatform}>
                        <SelectTrigger className="w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {platforms.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Input
                        value={externalId}
                        onChange={(event) => setExternalId(event.target.value)}
                        placeholder="act_1234567890"
                        className="w-full sm:w-52"
                    />
                    <Input
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        placeholder="Label (optional)"
                        className="w-full sm:w-44"
                    />
                    <Button
                        variant="outline"
                        disabled={externalId.trim() === ''}
                        onClick={() =>
                            router.post(
                                storeAdAccount({ client: clientId }).url,
                                {
                                    platform,
                                    external_account_id: externalId,
                                    name,
                                },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setExternalId('');
                                        setName('');
                                    },
                                },
                            )
                        }
                    >
                        <Plus className="size-4" />
                        Report on it
                    </Button>
                </div>
            )}
        </Panel>
    );
}

/** A figure, or an em dash when the platform did not report it. */
function Cell({ text }: { text: string | null }) {
    return (
        <td className="py-2.5 text-right font-mono text-xs tabular-nums">
            {text ?? <span className="text-muted-foreground">&mdash;</span>}
        </td>
    );
}
