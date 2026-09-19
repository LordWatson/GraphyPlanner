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
                <div className="relative overflow-hidden rounded-xl border border-border p-6 shadow-lg shadow-primary/10">
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 -z-10 bg-gradient-brand opacity-[0.14]"
                    />
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex items-center gap-4">
                            <span className="flex size-14 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                <UserRound className="size-6" />
                            </span>
                            <div className="flex flex-col gap-1">
                                <h1 className="font-serif text-2xl font-bold tracking-tight">
                                    <span className="text-gradient-brand">{member.name}</span>
                                </h1>
                                <p className="text-sm text-muted-foreground">{member.email}</p>
                                {member.role_label && (
                                    <Badge variant={roleVariant[member.role ?? ''] ?? 'outline'} className="mt-1 w-fit">
                                        {member.role_label}
                                    </Badge>
                                )}
                            </div>
                        </div>

                        <div className="flex items-center gap-2">
                            {member.can.update && (
                                <Button asChild size="sm" variant="outline">
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
                                    className="flex flex-col gap-1 rounded-lg border border-border bg-muted/20 p-3"
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
