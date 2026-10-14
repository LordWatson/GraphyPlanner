import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Lightbulb } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { show as showClient } from '@/routes/clients';
import { edit as editPost } from '@/routes/posts';
import aiPostIdeas from '@/routes/ai-post-ideas';

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

export default function AiPostIdeaShow({ client, idea }: { client: { id: number; name: string }; idea: IdeaData }) {
    return (
        <>
            <Head title={`${idea.title} — Post idea`} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
                <Link
                    href={clientAiTabUrl(client.id)}
                    className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Back to {client.name}
                </Link>

                <div className="grid gap-4 rounded-lg border border-border bg-card p-6">
                    <div className="flex items-start justify-between gap-3">
                        <Heading
                            title={idea.title}
                            description={`AI-recommended post idea for ${client.name}${idea.created_at ? ` · suggested ${idea.created_at}` : ''}`}
                        />
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Lightbulb className="size-4" />
                        </span>
                    </div>

                    <p className="text-sm whitespace-pre-wrap text-foreground">
                        {idea.description || <span className="text-muted-foreground">No description provided.</span>}
                    </p>

                    {idea.platforms.length > 0 && (
                        <div className="flex flex-wrap gap-1.5">
                            {idea.platforms.map((platform, i) => (
                                <Badge key={i} variant="outline">{platform}</Badge>
                            ))}
                        </div>
                    )}

                    {idea.suggestedCaption && (
                        <div className="grid gap-1">
                            <span className="text-xs font-medium text-muted-foreground">Suggested caption</span>
                            <p className="rounded-md border border-border bg-muted/20 p-3 text-sm whitespace-pre-wrap">
                                {idea.suggestedCaption}
                            </p>
                        </div>
                    )}

                    {idea.suggestedPostAt && (
                        <p className="text-xs text-muted-foreground">Suggested posting time: {idea.suggestedPostAt}</p>
                    )}

                    <div>
                        {idea.post_id ? (
                            <Button asChild size="sm" variant="secondary">
                                <Link href={editPost(idea.post_id)}>Open draft post</Link>
                            </Button>
                        ) : (
                            <Form {...aiPostIdeas.persist.form(idea.id)}>
                                {({ processing }) => (
                                    <Button type="submit" size="sm" disabled={processing}>
                                        {processing ? 'Creating draft…' : 'Turn into draft post'}
                                    </Button>
                                )}
                            </Form>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
