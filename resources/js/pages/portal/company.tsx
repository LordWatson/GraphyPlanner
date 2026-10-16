import { Head } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import type { ReactNode } from 'react';

import { Page, PageHeader } from '@/components/page';
import { StatusPill } from '@/components/status-pill';

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

function Field({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="border-border/70 flex flex-col gap-1.5 border-t pt-4">
            <dt className="eyebrow text-muted-foreground">{label}</dt>
            <dd className="text-[15px]">
                {value || (
                    <span className="text-muted-foreground">Not set</span>
                )}
            </dd>
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
        <>
            <Head title="Company — Client portal" />
            <Page>
                <PageHeader
                    eyebrow={`Company profile${client.start_date ? ` — with the studio since ${client.start_date}` : ''}`}
                    title={client.name}
                    description={
                        client.legal_name && client.legal_name !== client.name
                            ? client.legal_name
                            : undefined
                    }
                    meta={
                        <StatusPill
                            kind="client"
                            value={client.status}
                            label={client.status_label}
                        />
                    }
                />

                <section className="animate-rise border-border bg-card grid grid-cols-1 gap-8 rounded-2xl border p-6 sm:p-8 lg:grid-cols-[14rem_1fr]">
                    <div className="flex flex-col gap-4">
                        <span className="border-border bg-background font-display flex size-20 items-center justify-center rounded-full border text-3xl">
                            {client.name.charAt(0)}
                        </span>
                        <p className="text-muted-foreground text-[13px]">
                            This is how your company appears to the studio.
                            Spotted something out of date? Let your account
                            manager know.
                        </p>
                    </div>
                    <dl className="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2">
                        <Field label="Legal name" value={client.legal_name} />
                        <Field
                            label="Website"
                            value={
                                client.website ? (
                                    <a
                                        href={client.website}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="group decoration-border hover:decoration-primary inline-flex items-center gap-1 underline underline-offset-4"
                                    >
                                        {client.website.replace(
                                            /^https?:\/\//,
                                            '',
                                        )}
                                        <ArrowUpRight className="text-muted-foreground group-hover:text-primary size-3.5" />
                                    </a>
                                ) : null
                            }
                        />
                        <Field label="Industry" value={client.industry} />
                        <Field
                            label="Countries"
                            value={
                                client.countries.length > 0
                                    ? client.countries.join(', ')
                                    : null
                            }
                        />
                        <Field
                            label="Default language"
                            value={
                                client.default_language && (
                                    <span className="font-mono text-sm uppercase">
                                        {client.default_language}
                                    </span>
                                )
                            }
                        />
                        <Field
                            label="Client since"
                            value={
                                client.start_date && (
                                    <span className="font-mono text-sm tabular-nums">
                                        {client.start_date}
                                    </span>
                                )
                            }
                        />
                    </dl>
                </section>
            </Page>
        </>
    );
}
