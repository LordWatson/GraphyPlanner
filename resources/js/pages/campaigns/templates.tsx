import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, LayoutTemplate, Pencil, Trash2, Wand2 } from 'lucide-react';
import { useState } from 'react';
import {
    campaignSwatch,
    campaignSwatchText,
} from '@/components/campaigns/campaign-chip';
import type { Option } from '@/components/campaigns/types';
import type { TemplateSummary } from '@/components/campaigns/use-template-dialog';
import { UseTemplateDialog } from '@/components/campaigns/use-template-dialog';
import { confirmDialog } from '@/components/confirm-dialog';
import { EmptyState, Page, PageHeader } from '@/components/page';
import { platformIcon } from '@/components/platform-icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import {
    destroy as destroyTemplate,
    update as updateTemplate,
} from '@/routes/campaign-templates';
import { index as campaignsIndex } from '@/routes/campaigns';

type Template = TemplateSummary & {
    objective_label: string | null;
    phases: {
        name: string;
        start_offset: number;
        length: number;
        colour: string | null;
    }[];
    deliverables_count: number;
    has_brief: boolean;
    is_starter: boolean;
};

/**
 * Step C-3.4 — the studio's campaign templates: shapes that turn into a planned campaign in a
 * minute. Each card shows its phases to scale, what it plans and where.
 */
export default function CampaignTemplates({
    templates,
    clients,
    platformOptions,
}: {
    templates: Template[];
    clients: Option[];
    platformOptions: Option[];
}) {
    const [using, setUsing] = useState<Template | null>(null);
    const [editing, setEditing] = useState<Template | null>(null);

    const remove = async (template: Template) => {
        if (
            await confirmDialog({
                title: `Delete “${template.name}”?`,
                description:
                    'Campaigns already made from it stay exactly as they are.',
                confirmLabel: 'Delete template',
                tone: 'destructive',
            })
        ) {
            router.delete(destroyTemplate.url(template.id), {
                preserveScroll: true,
            });
        }
    };

    return (
        <>
            <Head title="Campaign templates" />
            <Page>
                <Link
                    href={campaignsIndex()}
                    className="text-muted-foreground hover:text-foreground -mb-4 inline-flex items-center gap-1.5 self-start text-sm"
                >
                    <ArrowLeft className="size-4" />
                    Campaigns
                </Link>
                <PageHeader
                    eyebrow="Campaigns — templates"
                    title={
                        <>
                            Start from a <em>template</em>.
                        </>
                    }
                    description="Start a campaign from a template and you’ll have a plan in a minute. Save any campaign as a template from its More menu."
                />

                {templates.length === 0 ? (
                    <EmptyState
                        icon={LayoutTemplate}
                        title="No templates yet."
                        description="Save a campaign you’re proud of as a template and reuse its shape for any client."
                    />
                ) : (
                    <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {templates.map((template) => (
                            <li
                                key={template.id}
                                className="border-border bg-card flex flex-col gap-4 rounded-2xl border p-5 shadow-sm"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="flex min-w-0 flex-col gap-1">
                                        <h2 className="font-display text-xl leading-tight">
                                            {template.name}
                                        </h2>
                                        <span className="text-muted-foreground font-mono text-[11px]">
                                            {template.duration_days} days ·{' '}
                                            {template.deliverables_count} posts
                                            {template.objective_label
                                                ? ` · ${template.objective_label}`
                                                : ''}
                                        </span>
                                    </div>
                                    {template.is_starter && (
                                        <Badge variant="lime">Starter</Badge>
                                    )}
                                </div>
                                {template.description && (
                                    <p className="text-muted-foreground text-sm text-pretty">
                                        {template.description}
                                    </p>
                                )}
                                {template.phases.length > 0 && (
                                    <div
                                        className="flex h-7 overflow-hidden rounded-full"
                                        aria-label="Phases"
                                    >
                                        {template.phases.map((phase, index) => (
                                            <span
                                                key={index}
                                                className={cn(
                                                    'flex min-w-0 items-center justify-center px-2 text-[10.5px] font-medium',
                                                    campaignSwatch(
                                                        phase.colour,
                                                    ),
                                                    campaignSwatchText(
                                                        phase.colour,
                                                    ),
                                                )}
                                                style={{
                                                    flexGrow: Math.max(
                                                        1,
                                                        phase.length,
                                                    ),
                                                }}
                                                title={`${phase.name}: ${phase.length} days`}
                                            >
                                                <span className="truncate">
                                                    {phase.name}
                                                </span>
                                            </span>
                                        ))}
                                    </div>
                                )}
                                <div className="text-muted-foreground flex items-center gap-2">
                                    {template.platforms.map((platform) => {
                                        const Icon = platformIcon(platform);

                                        return (
                                            <Icon
                                                key={platform}
                                                className="size-4"
                                                aria-label={platform}
                                            />
                                        );
                                    })}
                                    {template.has_brief && (
                                        <span className="text-xs">
                                            · includes a brief
                                        </span>
                                    )}
                                </div>
                                <div className="mt-auto flex flex-wrap items-center gap-2 pt-1">
                                    <Button onClick={() => setUsing(template)}>
                                        <Wand2 />
                                        Use template
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Edit ${template.name}`}
                                        onClick={() => setEditing(template)}
                                    >
                                        <Pencil />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="text-destructive"
                                        aria-label={`Delete ${template.name}`}
                                        onClick={() => remove(template)}
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Page>

            {using && (
                <UseTemplateDialog
                    key={using.id}
                    template={using}
                    clients={clients}
                    platformOptions={platformOptions}
                    open
                    onOpenChange={(open) => !open && setUsing(null)}
                />
            )}
            {editing && (
                <EditTemplateDialog
                    key={editing.id}
                    template={editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function EditTemplateDialog({
    template,
    onClose,
}: {
    template: Template;
    onClose: () => void;
}) {
    const [name, setName] = useState(template.name);
    const [description, setDescription] = useState(template.description ?? '');

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Edit template</DialogTitle>
                </DialogHeader>
                <div className="grid gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="tpl-name">Name</Label>
                        <Input
                            id="tpl-name"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="tpl-description">Description</Label>
                        <Textarea
                            id="tpl-description"
                            rows={3}
                            value={description}
                            onChange={(event) =>
                                setDescription(event.target.value)
                            }
                        />
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        onClick={() =>
                            router.put(
                                updateTemplate.url(template.id),
                                { name, description: description || null },
                                { preserveScroll: true, onSuccess: onClose },
                            )
                        }
                    >
                        Save
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

CampaignTemplates.layout = {
    breadcrumbs: [{ title: 'Campaigns', href: campaignsIndex() }],
};
