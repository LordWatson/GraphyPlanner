import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Lightbulb } from 'lucide-react';
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

                <div className="bg-noise relative flex flex-col gap-4 overflow-hidden rounded-2xl bg-gradient-brand-animated p-6 text-primary-foreground shadow-agency sm:p-8">
                    <span
                        aria-hidden
                        className="pointer-events-none absolute -top-16 -right-16 size-56 rounded-full bg-white/10 blur-2xl"
                    />
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 bg-[radial-gradient(white_1px,transparent_1px)] bg-[size:22px_22px] opacity-[0.08]"
                    />
                    <div className="relative flex items-start justify-between gap-3">
                        <div className="flex flex-col gap-1">
                            <span className="inline-flex w-fit items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-[10px] font-medium tracking-widest uppercase">
                                Post idea
                            </span>
                            <h1 className="font-serif text-2xl font-semibold tracking-tight italic sm:text-3xl">{idea.title}</h1>
                            <p className="text-sm text-primary-foreground/80">
                                For {client.name}{idea.created_at ? ` · suggested ${idea.created_at}` : ''}
                            </p>
                        </div>
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white/15 text-primary-foreground">
                            <Lightbulb className="size-5" />
                        </span>
                    </div>

                    <p className="relative text-sm whitespace-pre-wrap">
                        {idea.description || <span className="text-primary-foreground/70">No description provided.</span>}
                    </p>

                    {idea.platforms.length > 0 && (
                        <div className="relative flex flex-wrap gap-1.5">
                            {idea.platforms.map((platform, i) => (
                                <Badge key={i} variant="outline" className="border-white/30 bg-white/10 text-primary-foreground">{platform}</Badge>
                            ))}
                        </div>
                    )}

                    {idea.suggestedCaption && (
                        <div className="relative grid gap-1">
                            <span className="text-xs font-medium text-primary-foreground/80">Suggested caption</span>
                            <p className="rounded-xl border border-white/20 bg-white/10 p-3 text-sm whitespace-pre-wrap">
                                {idea.suggestedCaption}
                            </p>
                        </div>
                    )}

                    {idea.suggestedPostAt && (
                        <p className="relative text-xs text-primary-foreground/80">Suggested posting time: {idea.suggestedPostAt}</p>
                    )}

                    <div className="relative">
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
