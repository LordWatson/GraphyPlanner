import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import ClientPortalLayout from '@/layouts/client-portal/client-portal-layout';

type ClientData = {
    id: number;
    name: string;
    legal_name: string | null;
    website: string | null;
    industry: string | null;
    countries: string[];
    status: string;
    status_label: string;
    default_language: string | null;
    start_date: string | null;
};

const statusVariant: Record<string, 'success' | 'warning' | 'secondary' | 'outline' | 'destructive'> = {
    active: 'success',
    paused: 'warning',
    offboarding: 'destructive',
};

function Field({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="grid gap-1">
            <span className="text-xs font-medium text-muted-foreground">{label}</span>
            <span className="text-sm">{value || <span className="text-muted-foreground">Not set</span>}</span>
        </div>
    );
}

/**
 * Step 6.7 — the client portal's read-only "company" page: this client's own identity/profile
 * fields (name, industry, website, countries, status). No billing-sensitive fields are shown,
 * matching the "no billing visibility" boundary already enforced by `ClientPolicy::viewBilling`.
 */
export default function PortalCompany({ client }: { client: ClientData }) {
    return (
        <ClientPortalLayout title="Company">
            <Heading title="Company" description="Your company's profile as it appears in Graphy." />

            <div className="mt-6 grid gap-6 rounded-xl border border-border bg-card p-6 shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-lg font-semibold">{client.name}</h2>
                    <Badge variant={statusVariant[client.status] ?? 'outline'}>{client.status_label}</Badge>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Legal name" value={client.legal_name} />
                    <Field
                        label="Website"
                        value={
                            client.website ? (
                                <a href={client.website} target="_blank" rel="noreferrer" className="underline">
                                    {client.website}
                                </a>
                            ) : null
                        }
                    />
                    <Field label="Industry" value={client.industry} />
                    <Field label="Countries" value={client.countries.length > 0 ? client.countries.join(', ') : null} />
                    <Field label="Default language" value={client.default_language} />
                    <Field label="Client since" value={client.start_date} />
                </div>
            </div>
        </ClientPortalLayout>
    );
}
