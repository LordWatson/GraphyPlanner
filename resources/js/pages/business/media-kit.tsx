import { Deferred, Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    Check,
    Copy,
    Download,
    Eye,
    EyeOff,
    FileText,
    Link2,
} from 'lucide-react';
import MediaKitController from '@/actions/App/Http/Controllers/Business/MediaKitController';
import {
    MediaKitView,
    type MediaKitData,
} from '@/components/media-kit/media-kit-view';
import { ChapterHero, Page, Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { index, pdf, revoke, share } from '@/routes/business/media-kit';

type Kit = {
    bio: string | null;
    is_public: boolean;
    share_url: string | null;
    shared_at: string | null;
};

export default function BusinessMediaKit({
    kit,
    preview,
    canManage,
}: {
    kit: Kit;
    preview?: MediaKitData;
    canManage: boolean;
}) {
    return (
        <Page>
            <Head title="Media kit" />

            <ChapterHero
                eyebrow="Show your work"
                title={
                    <>
                        Your <em>media kit</em>.
                    </>
                }
                description="A one-page summary of your audience and best posts — share it with sponsors and partners, or download it as a PDF."
                actions={
                    <Button variant="on-ink" size="lg" asChild>
                        <a
                            href={pdf().url}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <Download className="size-4" />
                            Download PDF
                        </a>
                    </Button>
                }
            />

            {canManage && (
                <div className="grid gap-5 lg:grid-cols-2">
                    <BioCard bio={kit.bio} />
                    <ShareCard kit={kit} />
                </div>
            )}

            <Panel eyebrow="Preview" title="What people see">
                <Deferred
                    data="preview"
                    fallback={<Skeleton className="h-72 rounded-2xl" />}
                >
                    {preview ? (
                        <MediaKitView kit={preview} />
                    ) : (
                        <Skeleton className="h-72 rounded-2xl" />
                    )}
                </Deferred>
            </Panel>
        </Page>
    );
}

function BioCard({ bio }: { bio: string | null }) {
    return (
        <Panel icon={FileText} eyebrow="About" title="Your bio">
            <Form
                {...MediaKitController.update.form()}
                options={{ preserveScroll: true }}
                className="flex flex-col gap-3"
            >
                {({ processing }) => (
                    <>
                        <Textarea
                            name="bio"
                            defaultValue={bio ?? ''}
                            rows={5}
                            maxLength={1000}
                            placeholder="A short intro for sponsors — who you are, who you reach, and what you make."
                        />
                        <div>
                            <Button type="submit" disabled={processing}>
                                Save bio
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </Panel>
    );
}

function ShareCard({ kit }: { kit: Kit }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        if (!kit.share_url) return;
        try {
            await navigator.clipboard.writeText(kit.share_url);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard can be blocked; the field is selectable as a fallback.
        }
    };

    return (
        <Panel icon={Link2} eyebrow="Public link" title="Share your kit">
            {kit.is_public && kit.share_url ? (
                <div className="flex flex-col gap-4">
                    <p className="text-muted-foreground text-sm">
                        Anyone with this link can view your media kit. Turn it
                        off any time.
                    </p>
                    <div className="flex items-center gap-2">
                        <input
                            readOnly
                            value={kit.share_url}
                            onFocus={(e) => e.currentTarget.select()}
                            className="border-border bg-muted/40 min-w-0 flex-1 truncate rounded-lg border px-3 py-2 text-sm"
                        />
                        <Button type="button" variant="outline" onClick={copy}>
                            {copied ? (
                                <Check className="size-4" />
                            ) : (
                                <Copy className="size-4" />
                            )}
                            {copied ? 'Copied' : 'Copy'}
                        </Button>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    share().url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            New link
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() =>
                                router.delete(revoke().url, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            <EyeOff className="size-4" />
                            Turn off
                        </Button>
                    </div>
                </div>
            ) : (
                <div className="flex flex-col items-start gap-4">
                    <p className="text-muted-foreground text-sm">
                        Your kit is private. Create a public link to share it
                        with sponsors and partners.
                    </p>
                    <Button
                        type="button"
                        onClick={() =>
                            router.post(
                                share().url,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Eye className="size-4" />
                        Create public link
                    </Button>
                </div>
            )}
        </Panel>
    );
}

BusinessMediaKit.layout = {
    breadcrumbs: [{ title: 'Media kit', href: index() }],
};
