import { Head, Link } from '@inertiajs/react';
import { Building2, Plus } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, index, show } from '@/routes/clients';

type ClientRow = {
    id: number;
    name: string;
    industry: string | null;
    status: string;
    default_language: string | null;
    health: string;
    health_reason: string;
};

const statusVariant: Record<string, 'success' | 'warning' | 'secondary' | 'outline'> = {
    active: 'success',
    paused: 'warning',
    offboarding: 'secondary',
    archived: 'outline',
};

const healthVariant: Record<string, 'success' | 'warning' | 'destructive'> = {
    green: 'success',
    amber: 'warning',
    red: 'destructive',
};

export default function ClientsIndex({
    clients,
    can,
}: {
    clients: ClientRow[];
    can: { create: boolean };
}) {
    return (
        <>
            <Head title="Clients" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <Heading
                        title="Clients"
                        description="Manage the clients your team works with"
                    />
                    {can.create && (
                        <Button asChild>
                            <Link href={create()}>
                                <Plus />
                                Add client
                            </Link>
                        </Button>
                    )}
                </div>

                {clients.length === 0 ? (
                    <div className="flex min-h-[40vh] flex-1 flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border bg-card/50 p-8 text-center">
                        <Building2 className="size-6 text-muted-foreground" />
                        <p className="text-sm font-medium">No clients yet</p>
                        <p className="max-w-sm text-xs text-muted-foreground">
                            Add your first client to start organizing brands, campaigns, and posts.
                        </p>
                        {can.create && (
                            <Button asChild size="sm" className="mt-2">
                                <Link href={create()}>Add your first client</Link>
                            </Button>
                        )}
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-lg border border-border bg-card">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-border text-left text-xs text-muted-foreground">
                                    <th className="px-4 py-2 font-medium">Name</th>
                                    <th className="px-4 py-2 font-medium">Industry</th>
                                    <th className="px-4 py-2 font-medium">Language</th>
                                    <th className="px-4 py-2 font-medium">Status</th>
                                    <th className="px-4 py-2 font-medium">Health</th>
                                </tr>
                            </thead>
                            <tbody>
                                {clients.map((client) => (
                                    <tr
                                        key={client.id}
                                        className="border-b border-border last:border-0 hover:bg-muted/50"
                                    >
                                        <td className="px-4 py-2">
                                            <Link
                                                href={show(client.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {client.name}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {client.industry ?? '—'}
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {client.default_language ?? '—'}
                                        </td>
                                        <td className="px-4 py-2">
                                            <Badge variant={statusVariant[client.status] ?? 'outline'}>
                                                {client.status}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-2">
                                            <Badge
                                                variant={healthVariant[client.health] ?? 'outline'}
                                                title={client.health_reason}
                                            >
                                                {client.health}
                                            </Badge>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

ClientsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Clients',
            href: index(),
        },
    ],
};
