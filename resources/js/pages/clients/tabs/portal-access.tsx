import { Contact } from 'lucide-react';
import {
    ClientContactsPanel,
    type ContactData,
    type ContactRole,
} from '@/components/clients/client-contacts-panel';
import { index } from '@/routes/clients';
import type { ClientPageProps } from '@/components/clients/client-layout';
import {
    ClientLayout,
    TabHeading,
    TabPanel,
} from '@/components/clients/client-layout';

/**
 * Step I-2.4 — Contacts & portal access tab (`clients.portal-access`). One list: adding a contact
 * emails them their portal signup link, and the checkboxes on the row decide what they're sent.
 */
export default function ClientPortalAccessTab({
    contacts,
    contactRoles,
    canManageContacts,
    ...page
}: ClientPageProps & {
    contacts: ContactData[];
    contactRoles: ContactRole[];
    canManageContacts: boolean;
}) {
    const { client } = page;

    return (
        <ClientLayout {...page}>
            <TabPanel>
                <TabHeading
                    icon={Contact}
                    title="Client contacts & portal access"
                    description="The people at this client. Each one is emailed a link to set up their portal login, and the boxes below decide what they're notified about."
                />

                <ClientContactsPanel
                    clientId={client.id}
                    contacts={contacts}
                    roles={contactRoles}
                    canManage={canManageContacts}
                />
            </TabPanel>
        </ClientLayout>
    );
}

ClientPortalAccessTab.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }],
};
