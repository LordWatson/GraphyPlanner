import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, CalendarClock, Lightbulb } from 'lucide-react';
import { Page, PageHeader, Panel } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import aiPostIdeas from '@/routes/ai-post-ideas';
import { show as showClient } from '@/routes/clients';
import { edit as editPost } from '@/routes/posts';
import { formatDateTime } from '@/lib/dates';

const clientAiTabUrl = (clientId: number) => `${showClient(clientId).url}#ai`;

type IdeaData = {
    id: number;
    title: string;
    description: string | null;
    platforms: string[];
    suggestedCaption: string | null;
    suggestedPostAt: string | null;
    post_id: number | null;
    created_at: string | null;
};

export default function AiPostIdeaShow({
    client,
    idea,
}: {
    client: { id: number; name: string };
    idea: IdeaData;
}) {
    return (
        <>
            <Head title={`${idea.title} — Post idea`} />
            <Page width="narrow">
                <Link
                    href={clientAiTabUrl(client.id)}
                    className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1.5 text-sm"
                >
                    <ArrowLeft className="size-4" />
                    Back to {client.name}
                </Link>

                <PageHeader
                    eyebrow={`Post idea — ${client.name}${idea.created_at ? ` · ${formatDateTime(idea.created_at)}` : ''}`}
                    title={idea.title}
                    meta={idea.platforms.map((platform, i) => (
                        <Badge key={i} variant="outline" className="capitalize">
                            {platform}
                        </Badge>
                    ))}
                    actions={
                        idea.post_id ? (
                            <Button asChild>
                                <Link href={editPost(idea.post_id)}>
                                    Open draft post
                                    <ArrowRight />
                                </Link>
                            </Button>
                        ) : (
                            <Form {...aiPostIdeas.persist.form(idea.id)}>
                                {({ processing }) => (
                                    <Button type="submit" disabled={processing}>
                                        {processing
                                            ? 'Creating draft…'
                                            : 'Turn into draft post'}
                                    </Button>
                                )}
                            </Form>
                        )
                    }
                />

                <Panel eyebrow="The idea" icon={Lightbulb} title="Concept">
                    <p className="text-[15px] leading-relaxed whitespace-pre-wrap">
                        {idea.description || (
                            <span className="text-muted-foreground">
                                No description provided.
                            </span>
                        )}
                    </p>
                </Panel>

                {(idea.suggestedCaption || idea.suggestedPostAt) && (
                    <Panel eyebrow="Draft copy" title="Suggested caption">
                        <div className="flex flex-col gap-4">
                            {idea.suggestedCaption && (
                                <p className="border-primary border-l-2 pl-4 text-[15px] leading-relaxed whitespace-pre-wrap">
                                    {idea.suggestedCaption}
                                </p>
                            )}
                            {idea.suggestedPostAt && (
                                <p className="text-muted-foreground inline-flex items-center gap-1.5 font-mono text-xs">
                                    <CalendarClock className="size-3.5" />
                                    Suggested posting time:{' '}
                                    {idea.suggestedPostAt}
                                </p>
                            )}
                        </div>
                    </Panel>
                )}
            </Page>
        </>
    );
}
