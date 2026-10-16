import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowUpRight, Trash2, UserPlus, UsersRound } from 'lucide-react';
import { useState } from 'react';
import StaffInvitationController from '@/actions/App/Http/Controllers/StaffInvitationController';
import { confirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { EmptyState, PageHeader, Panel, StatStrip } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
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
import { index, show } from '@/routes/staff';
import { formatDate } from '@/lib/dates';

type Member = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    role_label: string | null;
};

type Invitation = {
    id: number;
    email: string;
    role: string;
    role_label: string;
    expires_at: string;
    accepted_at: string | null;
    revoked_at: string | null;
    is_pending: boolean;
    can: { delete: boolean };
};

type StaffIndexProps = {
    members: Member[];
    invitations: Invitation[];
    roles: { value: string; label: string }[];
    can: { invite: boolean };
};

function invitationState(invitation: Invitation): string {
    if (invitation.is_pending) {
        return 'pending';
    }

    if (invitation.accepted_at) {
        return 'accepted';
    }

    return invitation.revoked_at ? 'revoked' : 'expired';
}

function MemberCard({ member }: { member: Member }) {
    return (
        <Link
            href={show(member.id)}
            className="group border-border/80 bg-card hover:bg-muted/40 flex items-center gap-3.5 rounded-2xl border p-3.5 shadow-sm transition-colors"
        >
            <span className="bg-accent text-accent-foreground group-hover:bg-primary group-hover:text-primary-foreground font-display flex size-11 shrink-0 items-center justify-center rounded-full text-lg transition-colors">
                {member.name.charAt(0).toUpperCase()}
            </span>
            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="truncate text-[15px] font-semibold">
                    {member.name}
                </span>
                <span className="text-muted-foreground truncate text-xs">
                    {member.email}
                </span>
            </span>
            {member.role && (
                <StatusPill
                    kind="role"
                    value={member.role}
                    label={member.role_label}
                    className="shrink-0"
                />
            )}
            <ArrowUpRight className="text-muted-foreground size-4 shrink-0 opacity-0 transition-opacity group-hover:opacity-100" />
        </Link>
    );
}

function RosterGroup({
    title,
    caption,
    members,
}: {
    title: string;
    caption: string;
    members: Member[];
}) {
    return (
        <section className="flex flex-col gap-4">
            <div className="border-foreground/85 flex items-baseline justify-between gap-4 border-t pt-4">
                <h2 className="text-[17px] font-semibold tracking-[-0.01em]">
                    {title}
                </h2>
                <span className="eyebrow text-muted-foreground">{caption}</span>
            </div>
            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                {members.map((member) => (
                    <MemberCard key={member.id} member={member} />
                ))}
            </div>
        </section>
    );
}

export default function StaffIndex({
    members,
    invitations,
    roles,
    can,
}: StaffIndexProps) {
    const [revokingId, setRevokingId] = useState<number | null>(null);

    const pendingInvitations = invitations.filter(
        (invitation) => invitation.is_pending,
    );
    const otherInvitations = invitations.filter(
        (invitation) => !invitation.is_pending,
    );
    const studio = members.filter(
        (member) => member.role !== 'client_reviewer',
    );
    const reviewers = members.filter(
        (member) => member.role === 'client_reviewer',
    );

    const revoke = async (invitation: Invitation) => {
        if (
            !(await confirmDialog({
                title: `Revoke the invite for ${invitation.email}?`,
                description:
                    'Their link stops working straight away. You can always send a fresh one.',
                confirmLabel: 'Revoke invite',
                tone: 'destructive',
            }))
        ) {
            return;
        }

        router.delete(StaffInvitationController.destroy.url(invitation.id), {
            preserveScroll: true,
            onStart: () => setRevokingId(invitation.id),
            onFinish: () => setRevokingId(null),
        });
    };

    return (
        <>
            <Head title="Staff" />
            <div className="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-8 px-4 pt-6 pb-12 md:px-10 md:pt-10">
                <PageHeader
                    eyebrow={`${members.length} with access`}
                    title={
                        <>
                            Your <em>team</em>.
                        </>
                    }
                    description="Everyone with access to your organization, and anyone still waiting on an invite."
                />

                <StatStrip
                    className="animate-rise"
                    items={[
                        {
                            label: 'Studio',
                            value: String(studio.length).padStart(2, '0'),
                            caption: 'Owners, strategists, designers',
                        },
                        {
                            label: 'Client reviewers',
                            value: String(reviewers.length).padStart(2, '0'),
                            caption: 'Portal access only',
                        },
                        {
                            label: 'Pending',
                            value: String(pendingInvitations.length).padStart(
                                2,
                                '0',
                            ),
                            caption: 'Invites not yet accepted',
                        },
                    ]}
                />

                {can.invite && (
                    <Panel
                        eyebrow="Grow the team"
                        title="Invite someone"
                        icon={UserPlus}
                    >
                        <Form
                            {...StaffInvitationController.store.form()}
                            resetOnSuccess
                            className="flex flex-col gap-4 sm:flex-row sm:items-start"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid flex-1 gap-1.5">
                                        <Label htmlFor="email">Email</Label>
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            placeholder="name@studio.com"
                                            required
                                        />
                                        <InputError message={errors.email} />
                                    </div>

                                    <div className="grid gap-1.5 sm:w-52">
                                        <Label htmlFor="role">Role</Label>
                                        <Select
                                            name="role"
                                            defaultValue={roles[0]?.value}
                                        >
                                            <SelectTrigger id="role">
                                                <SelectValue placeholder="Select a role" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {roles.map((role) => (
                                                    <SelectItem
                                                        key={role.value}
                                                        value={role.value}
                                                    >
                                                        {role.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.role} />
                                    </div>

                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="sm:mt-5"
                                    >
                                        <UserPlus />
                                        Send invitation
                                    </Button>
                                </>
                            )}
                        </Form>
                    </Panel>
                )}

                {members.length === 0 ? (
                    <EmptyState
                        icon={UsersRound}
                        title="Just you, for now."
                        description="Invite your strategists, designers and client reviewers to start working together."
                    />
                ) : (
                    <>
                        {studio.length > 0 && (
                            <RosterGroup
                                title="The studio"
                                caption={`${studio.length} ${studio.length === 1 ? 'person' : 'people'}`}
                                members={studio}
                            />
                        )}
                        {reviewers.length > 0 && (
                            <RosterGroup
                                title="Client reviewers"
                                caption="Client portal only"
                                members={reviewers}
                            />
                        )}
                    </>
                )}

                {invitations.length > 0 && (
                    <section className="flex flex-col gap-4">
                        <div className="border-foreground/85 flex items-baseline justify-between gap-4 border-t pt-4">
                            <h2 className="text-[17px] font-semibold tracking-[-0.01em]">
                                Invitations
                            </h2>
                            <span className="eyebrow text-muted-foreground">
                                {pendingInvitations.length} pending
                            </span>
                        </div>
                        <ul className="border-border/80 bg-card divide-border/70 flex flex-col divide-y overflow-hidden rounded-2xl border shadow-sm">
                            {[...pendingInvitations, ...otherInvitations].map(
                                (invitation) => (
                                    <li
                                        key={invitation.id}
                                        className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5"
                                    >
                                        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                            <span className="truncate text-sm font-medium">
                                                {invitation.email}
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                {invitation.role_label}
                                                {invitation.is_pending &&
                                                    ` · expires ${formatDate(invitation.expires_at)}`}
                                            </span>
                                        </span>
                                        <StatusPill
                                            kind="invitation"
                                            value={invitationState(invitation)}
                                            className="shrink-0"
                                        />
                                        {invitation.is_pending &&
                                            invitation.can.delete && (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    className="text-destructive hover:bg-destructive/8 hover:text-destructive"
                                                    disabled={
                                                        revokingId ===
                                                        invitation.id
                                                    }
                                                    onClick={() =>
                                                        revoke(invitation)
                                                    }
                                                >
                                                    <Trash2 />
                                                    Revoke
                                                </Button>
                                            )}
                                    </li>
                                ),
                            )}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

StaffIndex.layout = {
    breadcrumbs: [
        {
            title: 'Staff',
            href: index(),
        },
    ],
};
