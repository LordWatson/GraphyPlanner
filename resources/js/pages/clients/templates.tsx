import { Head, router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { EmptyState, PageHeader } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { confirmDialog } from '@/components/confirm-dialog';
import { index as clientsIndex, create } from '@/routes/clients';
import { destroy, index } from '@/routes/client-templates';

type Template = {
    id: number;
    name: string;
    description: string | null;
    /** What the template will set, from `ClientTemplate::summary()`. */
    summary: string[];
    is_built_in: boolean;
    created_by: string | null;
};

export default function ClientTemplates({
    templates,
}: {
    templates: Template[];
}) {
    const remove = async (template: Template) => {
        if (
            await confirmDialog({
                title: `Remove “${template.name}”?`,
                description:
                    'Clients already created from it are not affected.',
                confirmLabel: 'Remove',
                tone: 'destructive',
            })
        ) {
            router.delete(destroy(template.id).url, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title="Client templates" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 py-6 md:px-8 md:py-9">
                <PageHeader
                    eyebrow="Onboarding"
                    title={
                        <>
                            Client <em>templates</em>.
                        </>
                    }
                    description="The settings you apply to every new client of a kind — approvals, cadence, billing, brand brain. Never a name or an email address."
                />

                {templates.length === 0 ? (
                    <EmptyState
                        title="No templates yet."
                        description="Set a client up the way you like, then use “Save as template” on their page."
                        action={
                            <Button asChild variant="outline">
                                <a href={clientsIndex().url}>Go to clients</a>
                            </Button>
                        }
                    />
                ) : (
                    <div className="grid gap-3">
                        {templates.map((template) => (
                            <div
                                key={template.id}
                                className="border-border bg-card grid gap-2 rounded-2xl border p-4 shadow-sm sm:p-5"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2 className="font-medium">
                                        {template.name}
                                    </h2>
                                    {template.is_built_in && (
                                        <Badge variant="lilac">Built in</Badge>
                                    )}
                                </div>

                                {template.description && (
                                    <p className="text-muted-foreground text-sm">
                                        {template.description}
                                    </p>
                                )}

                                {template.summary.length > 0 && (
                                    <p className="text-muted-foreground text-sm">
                                        Sets: {template.summary.join(' · ')}
                                    </p>
                                )}

                                <div className="flex items-center justify-between gap-3 pt-1">
                                    <p className="text-muted-foreground text-xs">
                                        {template.created_by
                                            ? `Saved by ${template.created_by}`
                                            : 'Shipped with Alima — edit or remove it freely'}
                                    </p>

                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => remove(template)}
                                    >
                                        <Trash2 />
                                        Remove
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                <p className="text-muted-foreground text-sm">
                    Pick one when you{' '}
                    <a className="underline" href={create().url}>
                        add a client
                    </a>
                    . Anything you type on that form wins over the template.
                </p>
            </div>
        </>
    );
}

ClientTemplates.layout = {
    breadcrumbs: [
        { title: 'Clients', href: clientsIndex() },
        { title: 'Templates', href: index() },
    ],
};
