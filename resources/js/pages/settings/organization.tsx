import { Form, Head } from '@inertiajs/react';
import OrganizationController from '@/actions/App/Http/Controllers/Settings/OrganizationController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit } from '@/routes/organization';

type Option = { value: string; label: string };

type OrganizationProps = {
    organization: {
        name: string;
        default_timezone: string;
        default_currency: string;
        has_upload_post_key: boolean;
        has_upload_post_webhook_secret: boolean;
        has_xai_key: boolean;
    };
    currencies: Option[];
};

export default function Organization({ organization, currencies }: OrganizationProps) {
    return (
        <>
            <Head title="Organization settings" />

            <h1 className="sr-only">Organization settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Organization"
                    description="Manage your organization's default timezone, default currency, and publish-adapter keys (Owner only)"
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
                                    defaultValue={
                                        organization.default_timezone
                                    }
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
                                    <SelectTrigger id="default_currency" className="w-full">
                                        <SelectValue placeholder="Select a currency" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {currencies.map((currency) => (
                                            <SelectItem key={currency.value} value={currency.value}>
                                                {currency.value} — {currency.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <InputError
                                    className="mt-2"
                                    message={errors.default_currency}
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
                                    placeholder={
                                        organization.has_upload_post_key
                                            ? 'Key is set — leave blank to keep it'
                                            : 'Not set'
                                    }
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
                                            ? 'Secret is set — leave blank to keep it'
                                            : 'Not set — copy the whsec_... value from the Upload-Post notifications dashboard'
                                    }
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.upload_post_webhook_secret}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="xai_key">xAI (Grok) API key</Label>

                                <Input
                                    id="xai_key"
                                    type="password"
                                    className="mt-1 block w-full"
                                    name="xai_key"
                                    autoComplete="off"
                                    placeholder={
                                        organization.has_xai_key
                                            ? 'Key is set — leave blank to keep it'
                                            : 'Not set'
                                    }
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
