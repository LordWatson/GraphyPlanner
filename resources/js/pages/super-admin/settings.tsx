import { Head, router } from '@inertiajs/react';

import { ChapterHero, Page } from '@/components/page';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import { billing } from '@/routes/super-admin/settings';

type PlanDistribution = {
    key: string;
    name: string;
    segment: string;
    count: number;
};

type SettingsProps = {
    billingEnabled: boolean;
    planDistribution: PlanDistribution[];
};

/**
 * Super admin → Billing (Step CG-2.6). The global Stripe master switch for self-serve checkout across
 * the whole platform, plus a snapshot of how organizations are distributed across plans. A per-org
 * override still lives on each organization's detail page.
 */
export default function PlatformSettings({
    billingEnabled,
    planDistribution,
}: SettingsProps) {
    const toggle = (next: boolean) => {
        router.put(billing().url, { enabled: next }, { preserveScroll: true });
    };

    return (
        <Page>
            <Head title="Platform billing" />

            <ChapterHero
                eyebrow="Platform · Billing"
                title={
                    <>
                        Stripe <em>billing</em>.
                    </>
                }
                description="The global switch for self-serve subscriptions. While it's off, no organization sees checkout — billing ships dark until you turn it on and real Stripe prices exist."
            />

            <div className="flex flex-col gap-10">
                <label
                    htmlFor="billing_enabled"
                    className="border-border bg-card hover:border-foreground/30 flex max-w-2xl cursor-pointer items-start gap-4 rounded-2xl border p-5 transition-colors"
                >
                    <Checkbox
                        id="billing_enabled"
                        checked={billingEnabled}
                        onCheckedChange={(checked) => toggle(checked === true)}
                        className="mt-0.5"
                    />
                    <div className="space-y-1">
                        <span className="text-foreground block text-sm font-semibold">
                            Stripe billing {billingEnabled ? 'on' : 'off'} for
                            the platform
                        </span>
                        <span className="text-muted-foreground block text-sm">
                            When on, organizations can start a trial, pay by
                            card and manage billing from Settings → Plan. A
                            per-org override on each organization can let one
                            through early or hold one back.
                        </span>
                    </div>
                </label>

                <div className="max-w-2xl space-y-4">
                    <h2 className="font-display text-xl">
                        Plans across the platform
                    </h2>
                    <div className="border-border overflow-hidden rounded-2xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left">
                                <tr>
                                    <th className="px-4 py-2.5 font-medium">
                                        Plan
                                    </th>
                                    <th className="px-4 py-2.5 font-medium">
                                        Segment
                                    </th>
                                    <th className="px-4 py-2.5 text-right font-medium">
                                        Organizations
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {planDistribution.map((row) => (
                                    <tr
                                        key={row.key}
                                        className="border-border/60 border-t"
                                    >
                                        <td className="px-4 py-2.5 font-medium">
                                            {row.name}
                                        </td>
                                        <td className="text-muted-foreground px-4 py-2.5 capitalize">
                                            {row.segment}
                                        </td>
                                        <td
                                            className={cn(
                                                'px-4 py-2.5 text-right tabular-nums',
                                                row.count === 0 &&
                                                    'text-muted-foreground',
                                            )}
                                        >
                                            {row.count}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </Page>
    );
}
