import { Form, Head, Link } from '@inertiajs/react';
import { Trash2, UserPlus, UsersRound } from 'lucide-react';
import StaffInvitationController from '@/actions/App/Http/Controllers/StaffInvitationController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
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
import { PageHeader } from '@/components/page';

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

export default function StaffIndex({
    members,
    invitations,
    roles,
    can,
}: StaffIndexProps) {
    const pendingInvitations = invitations.filter(
        (invitation) => invitation.is_pending,
    );
    const otherInvitations = invitations.filter(
        (invitation) => !invitation.is_pending,
    );

    return (
        <>
            <Head title="Staff" />
            <div className="mx-auto flex w-full max-w-[1360px] flex-1 flex-col gap-8 px-4 py-6 md:px-8 md:py-9">
                <PageHeader
                    eyebrow="Team"
                    title="Your team"
                    description="Everyone with access to your organization, and anyone still waiting on an invite."
                />

                {can.invite && (
                    <Form
                        {...StaffInvitationController.store.form()}
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

                                <div className="col-span-2 grid gap-1 sm:col-span-1">
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

                                <div className="col-span-full flex items-end sm:col-span-1">
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

                <div className="space-y-3">
                    <h2 className="text-muted-foreground text-sm font-medium">
                        Members
                    </h2>

                    {members.length === 0 ? (
                        <div className="border-border bg-card/50 flex min-h-[20vh] flex-1 flex-col items-center justify-center gap-2 rounded-2xl border border-dashed p-8 text-center">
                            <UsersRound className="text-muted-foreground size-6" />
                            <p className="text-sm font-medium">No staff yet</p>
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            {members.map((member) => (
                                <Link
                                    key={member.id}
                                    href={show(member.id)}
                                    className="group border-border bg-card hover:bg-muted/40 flex items-center gap-3 rounded-2xl border p-3.5 transition-colors"
                                >
                                    <span className="border-border bg-background group-hover:border-primary group-hover:bg-primary group-hover:text-primary-foreground flex size-10 shrink-0 items-center justify-center rounded-full border font-serif text-lg italic transition-colors">
                                        {member.name.charAt(0).toUpperCase()}
                                    </span>
                                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                                        <span className="truncate text-[15px] font-semibold">
                                            {member.name}
                                        </span>
                                        <span className="text-muted-foreground truncate text-xs">
                                            {member.email}
                                        </span>
                                    </div>
                                    {member.role && (
                                        <StatusPill
                                            kind="role"
                                            value={member.role}
                                            label={member.role_label}
                                            className="shrink-0"
                                        />
                                    )}
                                </Link>
                            ))}
                        </div>
                    )}
                </div>

                {pendingInvitations.length > 0 && (
                    <div className="space-y-3">
                        <h2 className="text-muted-foreground text-sm font-medium">
                            Pending invitations
                        </h2>

                        <div className="grid gap-3">
                            {pendingInvitations.map((invitation) => (
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
                                                <Badge variant="outline">
                                                    {invitation.role_label}
                                                </Badge>
                                                <Badge variant="warning">
                                                    pending
                                                </Badge>
                                            </div>
                                            <span className="text-muted-foreground text-xs">
                                                Expires {invitation.expires_at}
                                            </span>
                                        </div>
                                    </div>
                                    {invitation.can.delete && (
                                        <Form
                                            {...StaffInvitationController.destroy.form(
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
                    </div>
                )}

                {otherInvitations.length > 0 && (
                    <div className="space-y-3">
                        <h2 className="text-muted-foreground text-sm font-medium">
                            Invitation history
                        </h2>

                        <div className="grid gap-3">
                            {otherInvitations.map((invitation) => (
                                <div
                                    key={invitation.id}
                                    className="border-border bg-card flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3 shadow-sm"
                                >
                                    <div className="flex items-center gap-3">
                                        <div className="flex flex-col gap-1">
                                            <div className="flex items-center gap-2">
                                                <span className="text-sm font-medium">
                                                    {invitation.email}
                                                </span>
                                                <Badge variant="outline">
                                                    {invitation.role_label}
                                                </Badge>
                                                <Badge
                                                    variant={
                                                        invitation.accepted_at
                                                            ? 'success'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {invitation.accepted_at
                                                        ? 'accepted'
                                                        : invitation.revoked_at
                                                          ? 'revoked'
                                                          : 'expired'}
                                                </Badge>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
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
