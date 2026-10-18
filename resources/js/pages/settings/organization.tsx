import { Form, Head } from '@inertiajs/react';
import OrganizationController from '@/actions/App/Http/Controllers/Settings/OrganizationController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit, testUploadPost } from '@/routes/organization';

/** "Key is set (…a1b2)" — Step I-1.6 shows the last four characters, never the key. */
function storedHint(
    isSet: boolean,
    last4: string | null,
    noun: string,
): string {
    if (!isSet) {
        return 'Not set';
    }

    return `${noun} is set${last4 ? ` (…${last4})` : ''} — leave blank to keep it`;
}

type Option = { value: string; label: string };

type OrganizationProps = {
    organization: {
        name: string;
        default_timezone: string;
        default_currency: string;
        billing_details: string | null;
        payment_instructions: string | null;
        invoice_payment_terms_days: number;
        has_upload_post_key: boolean;
        has_upload_post_webhook_secret: boolean;
        has_xai_key: boolean;
        upload_post_key_last4: string | null;
        upload_post_webhook_secret_last4: string | null;
        xai_key_last4: string | null;
    };
    currencies: Option[];
};

export default function Organization({
    organization,
    currencies,
}: OrganizationProps) {
    return (
        <>
            <Head title="Organization settings" />

            <h1 className="sr-only">Organization settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Organization"
                    description="Manage your organization's defaults, invoicing profile, and publish-adapter keys (Owner only)"
                />

                <Form
                    {...OrganizationController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="default_timezone">
                                    Default timezone
                                </Label>

                                <Input
                                    id="default_timezone"
                                    className="mt-1 block w-full"
                                    defaultValue={organization.default_timezone}
                                    name="default_timezone"
                                    required
                                    placeholder="e.g. Europe/Berlin"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.default_timezone}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="default_currency">
                                    Default currency
                                </Label>

                                <Select
                                    name="default_currency"
                                    defaultValue={organization.default_currency}
                                >
                                    <SelectTrigger
                                        id="default_currency"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Select a currency" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {currencies.map((currency) => (
                                            <SelectItem
                                                key={currency.value}
                                                value={currency.value}
                                            >
                                                {currency.value} —{' '}
                                                {currency.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <InputError
                                    className="mt-2"
                                    message={errors.default_currency}
                                />
                            </div>

                            <div className="grid gap-6 pt-4">
                                <Heading
                                    variant="small"
                                    title="Invoicing"
                                    description="Printed on every branded invoice PDF and included in invoice emails"
                                />

                                <div className="grid gap-2">
                                    <Label htmlFor="invoice_payment_terms_days">
                                        Default payment terms (days)
                                    </Label>
                                    <Input
                                        id="invoice_payment_terms_days"
                                        name="invoice_payment_terms_days"
                                        type="number"
                                        min={0}
                                        max={365}
                                        className="mt-1 block w-32"
                                        defaultValue={
                                            organization.invoice_payment_terms_days
                                        }
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        New invoices without a due date are due
                                        this many days after issue. 0 = due on
                                        receipt.
                                    </p>
                                    <InputError
                                        className="mt-2"
                                        message={
                                            errors.invoice_payment_terms_days
                                        }
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="billing_details">
                                        Studio billing details
                                    </Label>
                                    <Textarea
                                        id="billing_details"
                                        name="billing_details"
                                        rows={3}
                                        defaultValue={
                                            organization.billing_details ?? ''
                                        }
                                        placeholder={
                                            'Address, company number, VAT/tax ID…'
                                        }
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={errors.billing_details}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="payment_instructions">
                                        How clients should pay
                                    </Label>
                                    <Textarea
                                        id="payment_instructions"
                                        name="payment_instructions"
                                        rows={3}
                                        defaultValue={
                                            organization.payment_instructions ??
                                            ''
                                        }
                                        placeholder={
                                            'Bank details, payment link, or other instructions'
                                        }
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={errors.payment_instructions}
                                    />
                                </div>
                            </div>

                            <div className="grid gap-6 pt-4">
                                <Heading
                                    variant="small"
                                    title="Integrations"
                                    description="API keys for publishing and AI — stored encrypted"
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="upload_post_key">
                                    Upload-Post API key
                                </Label>

                                <Input
                                    id="upload_post_key"
                                    type="password"
                                    className="mt-1 block w-full"
                                    name="upload_post_key"
                                    autoComplete="off"
                                    placeholder={storedHint(
                                        organization.has_upload_post_key,
                                        organization.upload_post_key_last4,
                                        'Key',
                                    )}
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.upload_post_key}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="upload_post_webhook_secret">
                                    Upload-Post webhook secret
                                </Label>

                                <Input
                                    id="upload_post_webhook_secret"
                                    type="password"
                                    className="mt-1 block w-full"
                                    name="upload_post_webhook_secret"
                                    autoComplete="off"
                                    placeholder={
                                        organization.has_upload_post_webhook_secret
                                            ? storedHint(
                                                  true,
                                                  organization.upload_post_webhook_secret_last4,
                                                  'Secret',
                                              )
                                            : 'Not set — copy the whsec_... value from the Upload-Post notifications dashboard'
                                    }
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.upload_post_webhook_secret}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="xai_key">
                                    xAI (Grok) API key
                                </Label>

                                <Input
                                    id="xai_key"
                                    type="password"
                                    className="mt-1 block w-full"
                                    name="xai_key"
                                    autoComplete="off"
                                    placeholder={storedHint(
                                        organization.has_xai_key,
                                        organization.xai_key_last4,
                                        'Key',
                                    )}
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.xai_key}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-organization-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                {organization.has_upload_post_key && (
                    <Form
                        {...testUploadPost.form()}
                        options={{ preserveScroll: true }}
                        className="border-border flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4"
                    >
                        {({ processing }) => (
                            <>
                                <p className="text-muted-foreground text-sm">
                                    Check that Upload-Post accepts your saved
                                    key. Nothing gets published.
                                </p>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    {processing
                                        ? 'Testing…'
                                        : 'Test connection'}
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

Organization.layout = {
    breadcrumbs: [
        {
            title: 'Organization settings',
            href: edit(),
        },
    ],
};
