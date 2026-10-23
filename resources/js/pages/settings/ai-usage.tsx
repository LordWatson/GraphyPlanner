import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import AiUsageController from '@/actions/App/Http/Controllers/Settings/AiUsageController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { StatStrip } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
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
    textUsage: {
        budget: number | null;
        used: number;
        remaining: number | null;
        requests: number;
        cost_estimate: number;
    };
    performance: {
        total: number;
        positive: number;
        negative: number;
        applied: number;
        acceptance_rate: number | null;
        top_dismissal_reasons: { reason: string; count: number }[];
    };
    settings: {
        ai_enabled: boolean;
        ai_monthly_token_budget: number | null;
    };
    aiConfigured: boolean;
    aiEnabled: boolean;
};

const money = new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 2,
});

const number = new Intl.NumberFormat();

/**
 * Settings → AI usage (Owner only). The Step M-7.1 control plane — the per-org AI toggle, this
 * month's text tokens/requests/cost against the monthly budget, and how helpful AI has been — plus
 * the Step 16.11 Image Studio quota.
 */
export default function AiUsage({
    usage,
    textUsage,
    performance,
    settings,
    aiConfigured,
    aiEnabled,
}: AiUsageProps) {
    const [enabled, setEnabled] = useState(settings.ai_enabled);

    const acceptance =
        performance.acceptance_rate === null
            ? '—'
            : `${Math.round(performance.acceptance_rate * 100)}%`;

    return (
        <>
            <Head title="AI usage" />

            <h1 className="sr-only">AI usage</h1>

            <div className="space-y-10">
                <Heading
                    variant="small"
                    title="AI"
                    description="Switch AI on for your studio, set a monthly budget, and see how it's being used. AI only ever suggests — a person always confirms before anything is saved or published."
                />

                {!aiConfigured && (
                    <p className="border-border bg-muted/40 text-muted-foreground rounded-2xl border p-4 text-sm">
                        AI isn&apos;t configured for this environment yet, so
                        the toggle below has no effect until it is. Nothing is
                        being generated.
                    </p>
                )}

                {/* Step M-7.1: the per-org enable toggle + monthly token budget. */}
                <Form
                    {...AiUsageController.updateSettings.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <label
                                htmlFor="ai_enabled"
                                className="border-border bg-card hover:border-foreground/30 flex cursor-pointer items-start gap-4 rounded-2xl border p-5 transition-colors"
                            >
                                <Checkbox
                                    id="ai_enabled"
                                    checked={enabled}
                                    onCheckedChange={(checked) =>
                                        setEnabled(checked === true)
                                    }
                                    className="mt-0.5"
                                />
                                <input
                                    type="hidden"
                                    name="ai_enabled"
                                    value={enabled ? '1' : '0'}
                                />

                                <div className="space-y-1">
                                    <span className="text-foreground block text-sm font-semibold">
                                        AI assistance
                                    </span>
                                    <span className="text-muted-foreground block text-sm">
                                        Suggest captions, ideas and trends,
                                        write with AI in the editor, and plan
                                        campaigns. Turn this off and every AI
                                        panel disappears for everyone in your
                                        studio.
                                    </span>
                                </div>
                            </label>

                            <div className="grid max-w-xs gap-2">
                                <Label htmlFor="ai_monthly_token_budget">
                                    Monthly AI budget (tokens)
                                </Label>
                                <Input
                                    id="ai_monthly_token_budget"
                                    name="ai_monthly_token_budget"
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    defaultValue={
                                        settings.ai_monthly_token_budget ?? ''
                                    }
                                    placeholder="No limit"
                                    aria-invalid={
                                        errors.ai_monthly_token_budget
                                            ? true
                                            : undefined
                                    }
                                />
                                <p className="text-muted-foreground text-xs">
                                    Leave blank for no limit. When the budget is
                                    reached, AI pauses with a clear message
                                    until the 1st of next month.
                                </p>
                                <InputError
                                    message={errors.ai_monthly_token_budget}
                                />
                            </div>

                            <Button type="submit" disabled={processing}>
                                Save AI settings
                            </Button>
                        </>
                    )}
                </Form>

                <Separator />

                {/* Step M-7.1: text usage this month. */}
                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="Text this month"
                        description={`Captions, ideas, trends and writing tools for ${usage.month}. Only real vendor calls count — cached results are free.`}
                    />

                    <StatStrip
                        items={[
                            {
                                label: 'Tokens',
                                value: number.format(textUsage.used),
                                caption:
                                    textUsage.budget === null
                                        ? 'No monthly budget'
                                        : `of ${number.format(textUsage.budget)} budgeted`,
                            },
                            {
                                label: 'Requests',
                                value: textUsage.requests,
                                caption: 'AI calls made',
                            },
                            {
                                label: 'Est. cost',
                                value: money.format(textUsage.cost_estimate),
                                caption: 'Vendor list prices',
                            },
                            {
                                label: 'Acceptance',
                                value: acceptance,
                                caption:
                                    performance.total === 0
                                        ? 'No ratings yet'
                                        : `${performance.positive} of ${performance.total} rated helpful`,
                            },
                        ]}
                    />

                    {performance.top_dismissal_reasons.length > 0 && (
                        <div className="text-muted-foreground text-sm">
                            <span className="text-foreground font-medium">
                                Common feedback:
                            </span>{' '}
                            {performance.top_dismissal_reasons
                                .map((r) => `${r.reason} (${r.count})`)
                                .join(', ')}
                        </div>
                    )}
                </div>

                <Separator />

                {/* Step 16.11: Image Studio quota. */}
                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="Images this month"
                        description={`Image Studio usage for ${usage.month}. Drafts, refinements and finals each count as one image; failed images don't count.`}
                    />

                    {!aiEnabled && (
                        <p className="text-muted-foreground text-sm">
                            AI is switched off for this environment, so no
                            images are being generated.
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
                                        Leave blank for no limit. Resets on the
                                        1st of each month.
                                    </p>
                                    <InputError
                                        message={errors.ai_monthly_image_quota}
                                    />
                                </div>

                                <Button disabled={processing}>
                                    Save limit
                                </Button>
                            </>
                        )}
                    </Form>
                </div>
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
