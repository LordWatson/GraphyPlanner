import Heading from '@/components/heading';
import ClientPortalLayout from '@/layouts/client-portal/client-portal-layout';

/**
 * Step 6.3 — the client portal's landing page shell. Real needs-attention content (outstanding
 * approvals, upcoming scheduled posts) lands in Step 6.4.
 */
export default function PortalDashboard({ client }: { client: { name: string } }) {
    return (
        <ClientPortalLayout title="Dashboard">
            <Heading title={`Welcome, ${client.name}`} description="Here's what's happening with your content." />

            <div className="mt-6 rounded-xl border border-dashed border-border bg-muted/20 p-6 text-center text-sm text-muted-foreground">
                Outstanding approvals and upcoming scheduled posts will appear here soon.
            </div>
        </ClientPortalLayout>
    );
}
