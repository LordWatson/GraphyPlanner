import { Form, Head, Link } from '@inertiajs/react';
import { ArrowRight, Pencil, Trash2, UserRound } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { edit, index, destroy } from '@/routes/staff';

type Member = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    role_label: string | null;
    created_at: string | null;
    can: { update: boolean; delete: boolean };
};

type ActivityLog = {
    id: number;
    action: string;
    from_role: string | null;
    to_role: string | null;
    note: string | null;
    actor_name: string | null;
    created_at: string | null;
};

const roleVariant: Record<string, 'success' | 'warning' | 'secondary' | 'outline'> = {
    owner: 'success',
    strategist: 'secondary',
    designer: 'secondary',
    viewer: 'outline',
};

const actionLabel: Record<string, string> = {
    invited: 'Invited',
    joined: 'Joined',
    role_changed: 'Role changed',
    removed: 'Removed',
};

export default function StaffShow({ member, activityLogs }: { member: Member; activityLogs: ActivityLog[] }) {
    return (
        <>
            <Head title={member.name} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
                <div className="bg-noise relative flex flex-wrap items-start justify-between gap-4 overflow-hidden rounded-2xl bg-gradient-brand-animated p-6 text-primary-foreground shadow-agency sm:p-8">
                    <span
                        aria-hidden
                        className="pointer-events-none absolute -top-16 -right-16 size-56 rounded-full bg-white/10 blur-2xl"
                    />
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 bg-[radial-gradient(white_1px,transparent_1px)] bg-[size:22px_22px] opacity-[0.08]"
                    />
                    <div className="relative flex items-center gap-4">
                        <span className="flex size-14 shrink-0 items-center justify-center rounded-full bg-white/15 text-primary-foreground">
                            <UserRound className="size-6" />
                        </span>
                        <div className="flex flex-col gap-1">
                            <span className="inline-flex w-fit items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-[10px] font-medium tracking-widest uppercase">
                                Team member
                            </span>
                            <h1 className="font-serif text-2xl font-semibold tracking-tight italic">{member.name}</h1>
                            <p className="text-sm text-primary-foreground/80">{member.email}</p>
                            {member.role_label && (
                                <Badge variant="secondary" className="mt-1 w-fit bg-white/90">
                                    {member.role_label}
                                </Badge>
                            )}
                        </div>
                    </div>

                    <div className="relative flex items-center gap-2">
                        {member.can.update && (
                            <Button asChild size="sm" variant="secondary" className="bg-white/90 hover:bg-white">
                                <Link href={edit(member.id)}>
                                    <Pencil />
                                    Edit
                                </Link>
                            </Button>
                        )}
                        {member.can.delete && (
                            <Form {...destroy.form(member.id)}>
                                {({ processing }) => (
                                    <Button type="submit" size="sm" variant="destructive" disabled={processing}>
                                        <Trash2 />
                                        Remove
                                    </Button>
                                )}
                            </Form>
                        )}
                    </div>
                </div>

                <div className="space-y-3">
                    <Heading variant="small" title="Activity" description="A history of everything that happened to this account" />

                    {activityLogs.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No activity recorded yet.</p>
                    ) : (
                        <ol className="space-y-3">
                            {activityLogs.map((log) => (
                                <li
                                    key={log.id}
                                    className="flex flex-col gap-1 rounded-xl border border-border bg-card p-3 shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-agency"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <div className="flex items-center gap-2 text-sm font-medium">
                                            <span>{actionLabel[log.action] ?? log.action}</span>
                                            {log.from_role && log.to_role && (
                                                <span className="flex items-center gap-1 text-xs text-muted-foreground">
                                                    <Badge variant="outline">{log.from_role}</Badge>
                                                    <ArrowRight className="size-3" />
                                                    <Badge variant="outline">{log.to_role}</Badge>
                                                </span>
                                            )}
                                        </div>
                                        <span className="text-xs text-muted-foreground">{log.created_at}</span>
                                    </div>
                                    {log.note && <p className="text-xs text-muted-foreground">{log.note}</p>}
                                    {log.actor_name && (
                                        <p className="text-xs text-muted-foreground">by {log.actor_name}</p>
                                    )}
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
            </div>
        </>
    );
}

StaffShow.layout = {
    breadcrumbs: [
        { title: 'Staff', href: index() },
        { title: 'Member', href: index() },
    ],
};
