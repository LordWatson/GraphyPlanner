import { Form, Head, Link } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { EmptyState, Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { index as inboxIndex } from '@/routes/inbox';
import { destroy, store } from '@/routes/inbox/alerts';

type Keyword = {
    id: number;
    term: string;
    added_by: string | null;
    matches: number;
};

/**
 * Inbox → Mention alerts. Keyword watching over the comments the app already ingests for connected
 * accounts — deliberately not "social listening", which would mean crawling the open web.
 */
export default function MentionAlerts({
    keywords,
    can,
}: {
    keywords: Keyword[];
    can: { manage: boolean };
}) {
    return (
        <Page>
            <Head title="Mention alerts" />

            <PageHeader
                eyebrow="Inbox"
                title={
                    <>
                        Mention <em>alerts</em>.
                    </>
                }
                description="Words worth knowing about the moment they appear — a brand name, a product, a complaint you keep seeing. Every new comment on a client's published post is checked, and the studio is notified. This watches the comments Alima already collects for connected accounts, not the open web."
            />

            {can.manage && (
                <Form
                    {...store.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess={['term']}
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid flex-1 gap-2 sm:max-w-sm">
                                <Label htmlFor="term">Word or phrase</Label>
                                <Input
                                    id="term"
                                    name="term"
                                    placeholder="refund"
                                    autoComplete="off"
                                    required
                                />
                                <InputError message={errors.term} />
                            </div>
                            <Button disabled={processing}>Watch for it</Button>
                        </>
                    )}
                </Form>
            )}

            {keywords.length === 0 ? (
                <EmptyState
                    title="Nothing watched yet."
                    description="Add a word above and you'll hear about the next comment that uses it."
                />
            ) : (
                <ul className="divide-border divide-y">
                    {keywords.map((keyword) => (
                        <li
                            key={keyword.id}
                            className="flex flex-wrap items-center justify-between gap-3 py-3"
                        >
                            <span className="grid gap-0.5">
                                <span className="font-medium">
                                    {keyword.term}
                                </span>
                                <span className="text-muted-foreground text-xs">
                                    {keyword.matches === 0
                                        ? 'No matches yet'
                                        : `${keyword.matches} ${keyword.matches === 1 ? 'match' : 'matches'}`}
                                    {keyword.added_by
                                        ? ` · added by ${keyword.added_by}`
                                        : ''}
                                </span>
                            </span>
                            {can.manage && (
                                <Form
                                    {...destroy.form(keyword.id)}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            disabled={processing}
                                            aria-label={`Stop watching ${keyword.term}`}
                                        >
                                            <Trash2 />
                                            Remove
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <p className="text-muted-foreground text-sm">
                <Link className="underline" href={inboxIndex()}>
                    Back to the inbox
                </Link>
            </p>
        </Page>
    );
}

MentionAlerts.layout = {
    breadcrumbs: [
        { title: 'Inbox', href: inboxIndex() },
        { title: 'Mention alerts', href: inboxIndex() },
    ],
};
