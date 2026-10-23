import { Form, router } from '@inertiajs/react';
import { Contact, Trash2, UserPlus } from 'lucide-react';
import InputError from '@/components/input-error';
import {
    ClientContactsPanel,
    type ContactData,
    type ContactRole,
} from '@/components/clients/client-contacts-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/clients';
import { store as storeInvitation } from '@/routes/clients/invitations';
import { destroy as destroyInvitation } from '@/routes/invitations';
import type { ClientPageProps } from '@/components/clients/client-layout';
import {
    ClientLayout,
    TabHeading,
    TabPanel,
} from '@/components/clients/client-layout';
import type { Paginated } from '@/components/pagination';
import { Pagination } from '@/components/pagination';

type InvitationData = {
    id: number;
    email: string;
    expires_at: string;
    accepted_at: string | null;
    revoked_at: string | null;
    is_pending: boolean;
    can: { delete: boolean };
};

/**
 * Step I-2.4 — Portal access tab (`clients.portal-access`): client invitations, paginated.
 */
export default function ClientPortalAccessTab({
    invitations: invitationPage,
    contacts,
    contactRoles,
    canManageContacts,
    ...page
}: ClientPageProps & {
    invitations: Paginated<InvitationData>;
    contacts: ContactData[];
    contactRoles: ContactRole[];
    canManageContacts: boolean;
}) {
    const { client, can } = page;
    const invitations = invitationPage.data;

    return (
        <ClientLayout {...page}>
            <TabPanel>
                <TabHeading
                    icon={Contact}
                    title="Client contacts"
                    description="The people at this client. Approvers get post review emails; billing contacts get invoices."
                />

                <ClientContactsPanel
                    clientId={client.id}
                    contacts={contacts}
                    roles={contactRoles}
                    canManage={canManageContacts}
                />
            </TabPanel>

            <TabPanel>
                <TabHeading
                    icon={UserPlus}
                    title="Client portal access"
                    description="Invite a named contact at this client to their own login"
                />

                <>
                    {can.createInvitation && (
                        <Form
                            {...storeInvitation.form(client.id)}
                            resetOnSuccess
                            className="border-border bg-card/50 grid grid-cols-2 gap-3 rounded-2xl border border-dashed p-4 shadow-sm sm:grid-cols-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="col-span-2 grid gap-1">
                                        <Label htmlFor="email">Email</Label>
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            required
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                    <div className="col-span-full">
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            <UserPlus />
                                            Send invitation
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}

                    {invitations.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No invitations sent yet.
                        </p>
                    ) : (
                        <div className="grid gap-3">
                            {invitations.map((invitation) => (
                                <div
                                    key={invitation.id}
                                    className="border-border bg-card hover:border-primary/30 hover:bg-muted/40 flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3 shadow-sm transition-colors"
                                >
                                    <div className="flex items-center gap-3">
                                        <span className="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-full">
                                            <UserPlus className="size-4" />
                                        </span>
                                        <div className="flex flex-col gap-1">
                                            <div className="flex items-center gap-2">
                                                <span className="text-sm font-medium">
                                                    {invitation.email}
                                                </span>
                                                <Badge
                                                    variant={
                                                        invitation.accepted_at
                                                            ? 'success'
                                                            : invitation.revoked_at
                                                              ? 'secondary'
                                                              : invitation.is_pending
                                                                ? 'warning'
                                                                : 'outline'
                                                    }
                                                >
                                                    {invitation.accepted_at
                                                        ? 'accepted'
                                                        : invitation.revoked_at
                                                          ? 'revoked'
                                                          : invitation.is_pending
                                                            ? 'pending'
                                                            : 'expired'}
                                                </Badge>
                                            </div>
                                            <span className="text-muted-foreground text-xs">
                                                Expires {invitation.expires_at}
                                            </span>
                                        </div>
                                    </div>
                                    {invitation.can.delete && (
                                        <Form
                                            {...destroyInvitation.form(
                                                invitation.id,
                                            )}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    <Trash2 />
                                                    Revoke
                                                </Button>
                                            )}
                                        </Form>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </>
            </TabPanel>
            <Pagination
                page={invitationPage}
                label="invitations"
                onPageChange={(page) =>
                    router.reload({ data: { page }, only: ['invitations'] })
                }
            />
        </ClientLayout>
    );
}

ClientPortalAccessTab.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }],
};
