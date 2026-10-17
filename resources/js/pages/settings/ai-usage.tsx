import { Form, Head } from '@inertiajs/react';
import AiUsageController from '@/actions/App/Http/Controllers/Settings/AiUsageController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { StatStrip } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/ai-usage';

type AiUsageProps = {
    usage: {
        month: string;
        used: number;
        quota: number | null;
        remaining: number | null;
        finals: number;
        cost_estimate: number;
        failed: number;
    };
    aiEnabled: boolean;
};

const money = new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 2,
});

/**
 * Settings → AI usage (Step 16.11, Owner only): this month's Image Studio images against the
 * quota, how many were finals, the estimated vendor cost, and the editable monthly limit.
 */
export default function AiUsage({ usage, aiEnabled }: AiUsageProps) {
    return (
        <>
            <Head title="AI usage" />

            <h1 className="sr-only">AI usage</h1>

            <div className="space-y-8">
                <Heading
                    variant="small"
                    title="AI usage"
                    description={`Image Studio usage for ${usage.month}. Drafts, refinements and finals each count as one image; failed images don't count.`}
                />

                {!aiEnabled && (
                    <p className="text-muted-foreground text-sm">
                        AI is switched off for this environment, so no images
                        are being generated.
                    </p>
                )}

                <StatStrip
                    items={[
                        {
                            label: 'Images',
                            value: usage.used,
                            caption:
                                usage.quota === null
                                    ? 'No monthly limit'
                                    : `of ${usage.quota} this month`,
                        },
                        {
                            label: 'Finals',
                            value: usage.finals,
                            caption: 'High-quality renders',
                        },
                        {
                            label: 'Est. cost',
                            value: money.format(usage.cost_estimate),
                            caption:
                                usage.failed > 0
                                    ? `${usage.failed} failed, not counted`
                                    : 'Vendor list prices',
                        },
                    ]}
                />

                <Form
                    {...AiUsageController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid max-w-xs gap-2">
                                <Label htmlFor="ai_monthly_image_quota">
                                    Monthly image limit
                                </Label>
                                <Input
                                    id="ai_monthly_image_quota"
                                    name="ai_monthly_image_quota"
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    defaultValue={usage.quota ?? ''}
                                    placeholder="No limit"
                                    aria-invalid={
                                        errors.ai_monthly_image_quota
                                            ? true
                                            : undefined
                                    }
                                />
                                <p className="text-muted-foreground text-xs">
                                    Leave blank for no limit. Resets on the 1st
                                    of each month.
                                </p>
                                <InputError
                                    message={errors.ai_monthly_image_quota}
                                />
                            </div>

                            <Button disabled={processing}>Save limit</Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

AiUsage.layout = {
    breadcrumbs: [
        {
            title: 'AI usage',
            href: edit(),
        },
    ],
};
