import { Form, Head, Link } from '@inertiajs/react';
import { Trash2, UserPlus, UsersRound } from 'lucide-react';
import StaffInvitationController from '@/actions/App/Http/Controllers/StaffInvitationController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
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

const roleVariant: Record<string, 'success' | 'warning' | 'secondary' | 'outline'> = {
    owner: 'success',
    strategist: 'secondary',
    designer: 'secondary',
    viewer: 'outline',
};

export default function StaffIndex({ members, invitations, roles, can }: StaffIndexProps) {
    const pendingInvitations = invitations.filter((invitation) => invitation.is_pending);
    const otherInvitations = invitations.filter((invitation) => !invitation.is_pending);

    return (
        <>
            <Head title="Staff" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="bg-noise relative flex flex-wrap items-center gap-3 overflow-hidden rounded-2xl bg-gradient-brand-animated p-6 text-primary-foreground shadow-agency sm:p-8">
                    <span
                        aria-hidden
                        className="pointer-events-none absolute -top-16 -right-16 size-56 rounded-full bg-white/10 blur-2xl"
                    />
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 bg-[radial-gradient(white_1px,transparent_1px)] bg-[size:22px_22px] opacity-[0.08]"
                    />
                    <div className="relative flex items-center gap-3">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white/15 text-primary-foreground">
                            <UsersRound className="size-5" />
                        </span>
                        <div className="flex flex-col gap-1">
                            <span className="inline-flex w-fit items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-[10px] font-medium tracking-widest uppercase">
                                Team
                            </span>
                            <h1 className="font-serif text-2xl font-semibold tracking-tight italic sm:text-3xl">Your team</h1>
                            <p className="text-sm text-primary-foreground/80">
                                Everyone with access to your organization, and anyone still waiting on an invite.
                            </p>
                        </div>
                    </div>
                </div>

                {can.invite && (
                    <Form
                        {...StaffInvitationController.store.form()}
                        resetOnSuccess
                        className="grid grid-cols-2 gap-3 rounded-2xl border border-dashed border-border bg-card/50 p-4 shadow-sm sm:grid-cols-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="col-span-2 grid gap-1">
                                    <Label htmlFor="email">Email</Label>
                                    <Input id="email" name="email" type="email" required />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="col-span-2 grid gap-1 sm:col-span-1">
                                    <Label htmlFor="role">Role</Label>
                                    <Select name="role" defaultValue={roles[0]?.value}>
                                        <SelectTrigger id="role">
                                            <SelectValue placeholder="Select a role" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {roles.map((role) => (
                                                <SelectItem key={role.value} value={role.value}>
                                                    {role.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.role} />
                                </div>

                                <div className="col-span-full flex items-end sm:col-span-1">
                                    <Button type="submit" size="sm" disabled={processing}>
                                        <UserPlus />
                                        Send invitation
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                )}

                <div className="space-y-3">
                    <h2 className="text-sm font-medium text-muted-foreground">Members</h2>

                    {members.length === 0 ? (
                        <div className="flex min-h-[20vh] flex-1 flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-border bg-card/50 p-8 text-center">
                            <UsersRound className="size-6 text-muted-foreground" />
                            <p className="text-sm font-medium">No staff yet</p>
                        </div>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2">
                            {members.map((member) => (
                                <Link
                                    key={member.id}
                                    href={show(member.id)}
                                    className="flex items-center gap-3 rounded-xl border border-border bg-card p-3 shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-agency"
                                >
                                    <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-gradient-brand text-sm font-semibold text-white shadow-sm">
                                        {member.name.charAt(0).toUpperCase()}
                                    </span>
                                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                                        <span className="truncate font-serif text-sm font-semibold italic">{member.name}</span>
                                        <span className="truncate text-xs text-muted-foreground">{member.email}</span>
                                    </div>
                                    {member.role && (
                                        <Badge variant={roleVariant[member.role] ?? 'outline'} className="shrink-0">
                                            {member.role_label}
                                        </Badge>
                                    )}
                                </Link>
                            ))}
                        </div>
                    )}
                </div>

                {pendingInvitations.length > 0 && (
                    <div className="space-y-3">
                        <h2 className="text-sm font-medium text-muted-foreground">Pending invitations</h2>

                        <div className="grid gap-3">
                            {pendingInvitations.map((invitation) => (
                                <div
                                    key={invitation.id}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-3 shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-agency"
                                >
                                    <div className="flex items-center gap-3">
                                        <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                            <UserPlus className="size-4" />
                                        </span>
                                        <div className="flex flex-col gap-1">
                                            <div className="flex items-center gap-2">
                                                <span className="text-sm font-medium">{invitation.email}</span>
                                                <Badge variant="outline">{invitation.role_label}</Badge>
                                                <Badge variant="warning">pending</Badge>
                                            </div>
                                            <span className="text-xs text-muted-foreground">
                                                Expires {invitation.expires_at}
                                            </span>
                                        </div>
                                    </div>
                                    {invitation.can.delete && (
                                        <Form {...StaffInvitationController.destroy.form(invitation.id)}>
                                            {({ processing }) => (
                                                <Button type="submit" size="sm" variant="outline" disabled={processing}>
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
                        <h2 className="text-sm font-medium text-muted-foreground">Invitation history</h2>

                        <div className="grid gap-3">
                            {otherInvitations.map((invitation) => (
                                <div
                                    key={invitation.id}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-3 shadow-sm"
                                >
                                    <div className="flex items-center gap-3">
                                        <div className="flex flex-col gap-1">
                                            <div className="flex items-center gap-2">
                                                <span className="text-sm font-medium">{invitation.email}</span>
                                                <Badge variant="outline">{invitation.role_label}</Badge>
                                                <Badge variant={invitation.accepted_at ? 'success' : 'secondary'}>
                                                    {invitation.accepted_at ? 'accepted' : invitation.revoked_at ? 'revoked' : 'expired'}
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
