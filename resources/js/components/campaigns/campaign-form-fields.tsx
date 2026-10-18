import type { InertiaFormProps } from '@inertiajs/react';
import { campaignColourClass } from '@/components/campaigns/campaign-chip';
import type { Option } from '@/components/campaigns/types';
import { HashtagInput } from '@/components/hashtag-input';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';

export const NONE = '__none';

export type CampaignFormOptions = {
    owners: Option[];
    objectives: Option[];
    colours: Option[];
    platforms: Option[];
    billingTypes: Option[];
};

export type CampaignFormData = {
    name: string;
    code: string;
    owner_id: string;
    objective: string;
    colour: string;
    platforms: string[];
    start_date: string;
    end_date: string;
    goal: string;
    notes: string;
    hashtags: string[];
    default_link_url: string;
    utm_campaign: string;
    billing_type: string;
    fee_amount: string;
};

/** The server shape: empty strings and the "none" sentinel become nulls. */
export function toPayload(data: CampaignFormData): Record<string, unknown> {
    const blank = (value: string) =>
        value === '' || value === NONE ? null : value;

    return {
        ...data,
        code: blank(data.code),
        owner_id: blank(data.owner_id),
        objective: blank(data.objective),
        colour: blank(data.colour),
        start_date: blank(data.start_date),
        end_date: blank(data.end_date),
        goal: blank(data.goal),
        notes: blank(data.notes),
        default_link_url: blank(data.default_link_url),
        utm_campaign: blank(data.utm_campaign),
        billing_type: blank(data.billing_type),
        fee_amount:
            data.billing_type === 'fixed_fee' ? blank(data.fee_amount) : null,
    };
}

type Form = InertiaFormProps<CampaignFormData>;

function Field({
    label,
    htmlFor,
    error,
    children,
    hint,
    className,
}: {
    label: string;
    htmlFor?: string;
    error?: string;
    children: React.ReactNode;
    hint?: string;
    className?: string;
}) {
    return (
        <div className={cn('grid gap-1.5', className)}>
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

/**
 * Step C-0.2 — the campaign's core fields (create and settings share them): name, code, owner,
 * objective, colour, dates, platforms, goal.
 */
export function CampaignCoreFields({
    form,
    options,
}: {
    form: Form;
    options: CampaignFormOptions;
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <Field
                label="Name"
                htmlFor="campaign-name"
                error={form.errors.name}
                className="sm:col-span-2"
            >
                <Input
                    id="campaign-name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    placeholder="Spring launch"
                    required
                />
            </Field>
            <Field
                label="Code"
                htmlFor="campaign-code"
                error={form.errors.code}
                hint="Short reference on chips and invoices."
            >
                <Input
                    id="campaign-code"
                    value={form.data.code}
                    onChange={(event) =>
                        form.setData('code', event.target.value.toUpperCase())
                    }
                    placeholder="SPR26"
                    maxLength={12}
                    className="font-mono uppercase"
                />
            </Field>
            <Field
                label="Owner"
                error={form.errors.owner_id}
                hint="Who’s accountable for it."
            >
                <Select
                    value={form.data.owner_id}
                    onValueChange={(value) => form.setData('owner_id', value)}
                >
                    <SelectTrigger className="w-full">
                        <SelectValue placeholder="Pick someone" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>Nobody yet</SelectItem>
                        {options.owners.map((owner) => (
                            <SelectItem key={owner.value} value={owner.value}>
                                {owner.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>
            <Field
                label="Starts"
                htmlFor="campaign-start"
                error={form.errors.start_date}
            >
                <Input
                    id="campaign-start"
                    type="date"
                    value={form.data.start_date}
                    onChange={(event) =>
                        form.setData('start_date', event.target.value)
                    }
                />
            </Field>
            <Field
                label="Ends"
                htmlFor="campaign-end"
                error={form.errors.end_date}
            >
                <Input
                    id="campaign-end"
                    type="date"
                    min={form.data.start_date || undefined}
                    value={form.data.end_date}
                    onChange={(event) =>
                        form.setData('end_date', event.target.value)
                    }
                />
            </Field>
            <Field label="Objective" error={form.errors.objective}>
                <Select
                    value={form.data.objective}
                    onValueChange={(value) => form.setData('objective', value)}
                >
                    <SelectTrigger className="w-full">
                        <SelectValue placeholder="What’s it for?" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>Not set</SelectItem>
                        {options.objectives.map((objective) => (
                            <SelectItem
                                key={objective.value}
                                value={objective.value}
                            >
                                {objective.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>
            <fieldset className="grid gap-1.5">
                <legend className="mb-1.5 text-sm font-medium">Colour</legend>
                <div className="flex flex-wrap gap-2">
                    {options.colours.map((colour) => (
                        <button
                            key={colour.value}
                            type="button"
                            aria-label={colour.label}
                            aria-pressed={form.data.colour === colour.value}
                            title={colour.label}
                            onClick={() =>
                                form.setData(
                                    'colour',
                                    form.data.colour === colour.value
                                        ? ''
                                        : colour.value,
                                )
                            }
                            className={cn(
                                'ring-offset-background focus-visible:ring-ring size-8 rounded-full ring-offset-2 outline-none focus-visible:ring-[3px]',
                                campaignColourClass[colour.value],
                                form.data.colour === colour.value &&
                                    'ring-foreground ring-2',
                            )}
                        />
                    ))}
                </div>
                <InputError message={form.errors.colour} />
            </fieldset>
            <Field
                label="Platforms"
                error={form.errors.platforms}
                className="sm:col-span-2"
            >
                <ToggleGroup
                    type="multiple"
                    value={form.data.platforms}
                    onValueChange={(value) => form.setData('platforms', value)}
                    className="flex-wrap justify-start"
                >
                    {options.platforms.map((platform) => (
                        <ToggleGroupItem
                            key={platform.value}
                            value={platform.value}
                            className="px-3"
                        >
                            {platform.label}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            </Field>
            <Field
                label="Goal"
                htmlFor="campaign-goal"
                error={form.errors.goal}
                hint="One line: what does success look like?"
                className="sm:col-span-2"
            >
                <Textarea
                    id="campaign-goal"
                    rows={2}
                    maxLength={500}
                    value={form.data.goal}
                    onChange={(event) =>
                        form.setData('goal', event.target.value)
                    }
                />
            </Field>
        </div>
    );
}

/**
 * Step C-0.2 / C-4.2 / C-8.1 — the settings-only fields: campaign defaults for posts (hashtags,
 * link, UTM name), internal notes and, for Owner/Strategist, how it's billed.
 */
export function CampaignSettingsFields({
    form,
    options,
    canBill,
}: {
    form: Form;
    options: CampaignFormOptions;
    canBill: boolean;
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <Field
                label="Campaign hashtags"
                error={form.errors.hashtags}
                hint="Offered to every post that joins the campaign."
                className="sm:col-span-2"
            >
                <HashtagInput
                    value={form.data.hashtags}
                    onChange={(tags) => form.setData('hashtags', tags)}
                />
            </Field>
            <Field
                label="Default link"
                htmlFor="campaign-link"
                error={form.errors.default_link_url}
            >
                <Input
                    id="campaign-link"
                    type="url"
                    value={form.data.default_link_url}
                    onChange={(event) =>
                        form.setData('default_link_url', event.target.value)
                    }
                    placeholder="https://"
                />
            </Field>
            <Field
                label="UTM campaign name"
                htmlFor="campaign-utm"
                error={form.errors.utm_campaign}
                hint="Added to the link as utm_campaign."
            >
                <Input
                    id="campaign-utm"
                    value={form.data.utm_campaign}
                    onChange={(event) =>
                        form.setData(
                            'utm_campaign',
                            event.target.value.toLowerCase(),
                        )
                    }
                    placeholder="spring-launch"
                    className="font-mono"
                />
            </Field>
            <Field
                label="Internal notes"
                htmlFor="campaign-notes"
                error={form.errors.notes}
                hint="Never shown to the client."
                className="sm:col-span-2"
            >
                <Textarea
                    id="campaign-notes"
                    rows={3}
                    value={form.data.notes}
                    onChange={(event) =>
                        form.setData('notes', event.target.value)
                    }
                />
            </Field>
            {canBill && (
                <>
                    <Field label="Billing" error={form.errors.billing_type}>
                        <Select
                            value={form.data.billing_type}
                            onValueChange={(value) =>
                                form.setData('billing_type', value)
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="How is it paid for?" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NONE}>Not set</SelectItem>
                                {options.billingTypes.map((type) => (
                                    <SelectItem
                                        key={type.value}
                                        value={type.value}
                                    >
                                        {type.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field
                        label="Fee"
                        htmlFor="campaign-fee"
                        error={form.errors.fee_amount}
                    >
                        <Input
                            id="campaign-fee"
                            type="number"
                            min={0}
                            step="0.01"
                            disabled={form.data.billing_type !== 'fixed_fee'}
                            value={form.data.fee_amount}
                            onChange={(event) =>
                                form.setData('fee_amount', event.target.value)
                            }
                            className="tabular-nums"
                        />
                    </Field>
                </>
            )}
        </div>
    );
}
