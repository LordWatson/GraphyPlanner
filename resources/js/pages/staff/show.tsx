import { Form, Head, Link } from '@inertiajs/react';
import { ArrowRight, Pencil, Trash2 } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { edit, index, destroy } from '@/routes/staff';
import { PageHeader } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { formatDateTime } from '@/lib/dates';

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

const actionLabel: Record<string, string> = {
    invited: 'Invited',
    joined: 'Joined',
    role_changed: 'Role changed',
    removed: 'Removed',
};

export default function StaffShow({
    member,
    activityLogs,
}: {
    member: Member;
    activityLogs: ActivityLog[];
}) {
    return (
        <>
            <Head title={member.name} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 py-6 md:px-8 md:py-9">
                <PageHeader
                    eyebrow="Team member"
                    title={member.name}
                    description={member.email}
                    meta={
                        member.role_label &&
                        member.role && (
                            <StatusPill
                                kind="role"
                                value={member.role}
                                label={member.role_label}
                            />
                        )
                    }
                    actions={
                        <>
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
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="destructive"
                                            disabled={processing}
                                        >
                                            <Trash2 />
                                            Remove
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </>
                    }
                />

                <div className="space-y-3">
                    <Heading
                        variant="small"
                        title="Activity"
                        description="A history of everything that happened to this account"
                    />

                    {activityLogs.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            No activity recorded yet.
                        </p>
                    ) : (
                        <ol className="space-y-3">
                            {activityLogs.map((log) => (
                                <li
                                    key={log.id}
                                    className="border-border bg-card hover:bg-muted/40 flex flex-col gap-1 rounded-xl border p-3 shadow-sm transition-colors"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <div className="flex items-center gap-2 text-sm font-medium">
                                            <span>
                                                {actionLabel[log.action] ??
                                                    log.action}
                                            </span>
                                            {log.from_role && log.to_role && (
                                                <span className="text-muted-foreground flex items-center gap-1 text-xs">
                                                    <Badge variant="outline">
                                                        {log.from_role}
                                                    </Badge>
                                                    <ArrowRight className="size-3" />
                                                    <Badge variant="outline">
                                                        {log.to_role}
                                                    </Badge>
                                                </span>
                                            )}
                                        </div>
                                        <span className="text-muted-foreground text-xs">
                                            {formatDateTime(log.created_at)}
                                        </span>
                                    </div>
                                    {log.note && (
                                        <p className="text-muted-foreground text-xs">
                                            {log.note}
                                        </p>
                                    )}
                                    {log.actor_name && (
                                        <p className="text-muted-foreground text-xs">
                                            by {log.actor_name}
                                        </p>
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
