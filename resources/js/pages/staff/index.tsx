import { Form, Head, Link, router } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Trash2, UserPlus, UsersRound } from 'lucide-react';
import { useState } from 'react';
import StaffInvitationController from '@/actions/App/Http/Controllers/StaffInvitationController';
import { confirmDialog } from '@/components/confirm-dialog';
import { DataTable } from '@/components/data-table';
import InputError from '@/components/input-error';
import {
    ChapterHero,
    EmptyState,
    HeroStat,
    Page,
    Panel,
    StatStrip,
} from '@/components/page';
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

/** Step M-1.4: the roster on the shared `DataTable`, sortable by name and role in place. */
const memberColumns: ColumnDef<Member, unknown>[] = [
    {
        id: 'name',
        accessorKey: 'name',
        header: 'Name',
        enableSorting: true,
        enableHiding: false,
        cell: ({ row }) => (
            <Link
                href={show(row.original.id)}
                className="group flex min-w-0 items-center gap-3"
            >
                <span className="bg-accent text-accent-foreground group-hover:bg-primary group-hover:text-primary-foreground font-display flex size-10 shrink-0 items-center justify-center rounded-full text-lg transition-colors">
                    {row.original.name.charAt(0).toUpperCase()}
                </span>
                <span className="flex min-w-0 flex-col gap-0.5">
                    <span className="group-hover:text-primary truncate text-[15px] font-semibold transition-colors">
                        {row.original.name}
                    </span>
                    <span className="text-muted-foreground truncate text-xs sm:hidden">
                        {row.original.email}
                    </span>
                </span>
            </Link>
        ),
    },
    {
        id: 'email',
        accessorKey: 'email',
        header: 'Email',
        enableSorting: true,
        meta: {
            label: 'Email',
            hideBelow: 'sm',
            className: 'text-muted-foreground max-w-64 truncate text-sm',
        },
    },
    {
        id: 'role',
        accessorFn: (member) => member.role_label ?? '',
        header: 'Role',
        enableSorting: true,
        enableHiding: false,
        cell: ({ row }) =>
            row.original.role && (
                <StatusPill
                    kind="role"
                    value={row.original.role}
                    label={row.original.role_label}
                />
            ),
    },
];

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
            <DataTable
                caption={title}
                columns={memberColumns}
                data={members}
                getRowId={(member) => String(member.id)}
                rowHref={(member) => show.url(member.id)}
                pageSize={25}
                paginationLabel="people"
            />
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
            <Page>
                <ChapterHero
                    eyebrow={`${members.length} with access`}
                    title={
                        <>
                            Your <em>team</em>.
                        </>
                    }
                    description="Everyone with access to your organization, and anyone still waiting on an invite."
                    actions={
                        can.invite && (
                            <Button variant="spark" size="lg" asChild>
                                <a href="#invite">
                                    <UserPlus />
                                    Invite someone
                                </a>
                            </Button>
                        )
                    }
                    aside={
                        <HeroStat
                            icon={UsersRound}
                            eyebrow="With access"
                            value={String(members.length).padStart(2, '0')}
                            caption="Studio + client reviewers"
                        />
                    }
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
                        id="invite"
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
                        <DataTable
                            caption="Invitations"
                            data={[...pendingInvitations, ...otherInvitations]}
                            getRowId={(invitation) => String(invitation.id)}
                            pageSize={25}
                            paginationLabel="invitations"
                            columns={[
                                {
                                    id: 'email',
                                    header: 'Invitee',
                                    cell: ({ row }) => (
                                        <span className="flex min-w-0 flex-col gap-0.5">
                                            <span className="truncate text-sm font-medium">
                                                {row.original.email}
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                {row.original.role_label}
                                                {row.original.is_pending &&
                                                    ` · expires ${formatDate(row.original.expires_at)}`}
                                            </span>
                                        </span>
                                    ),
                                },
                                {
                                    id: 'state',
                                    header: 'Status',
                                    cell: ({ row }) => (
                                        <StatusPill
                                            kind="invitation"
                                            value={invitationState(
                                                row.original,
                                            )}
                                        />
                                    ),
                                },
                                {
                                    id: 'actions',
                                    header: () => (
                                        <span className="sr-only">Actions</span>
                                    ),
                                    meta: {
                                        className: 'w-0 whitespace-nowrap',
                                    },
                                    cell: ({ row }) =>
                                        row.original.is_pending &&
                                        row.original.can.delete && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                className="text-destructive hover:bg-destructive/8 hover:text-destructive"
                                                disabled={
                                                    revokingId ===
                                                    row.original.id
                                                }
                                                onClick={() =>
                                                    void revoke(row.original)
                                                }
                                            >
                                                <Trash2 />
                                                Revoke
                                            </Button>
                                        ),
                                },
                            ]}
                        />
                    </section>
                )}
            </Page>
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
