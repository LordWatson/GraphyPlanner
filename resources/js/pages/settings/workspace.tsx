import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { CheckCircle2 } from 'lucide-react';
import WorkspaceController from '@/actions/App/Http/Controllers/Settings/WorkspaceController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { edit } from '@/routes/workspace-settings';

type WorkspaceProps = {
    settings: {
        name: string;
        require_post_review: boolean;
        insights_digest_opt_out: boolean;
    };
};

/**
 * Step B-3.3 / B-8.2 — the business workspace's own settings: the review switch (on, posts go
 * through an internal review step before they go out; off, the owner schedules in one tap) and the
 * weekly Insights email digest opt-out. On brand: clearly-explained choices, not a wall of toggles.
 */
export default function WorkspaceSettings({ settings }: WorkspaceProps) {
    const [requireReview, setRequireReview] = useState(
        settings.require_post_review,
    );
    const [digestOff, setDigestOff] = useState(
        settings.insights_digest_opt_out,
    );

    return (
        <>
            <Head title="Workspace settings" />

            <h1 className="sr-only">Workspace settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Workspace"
                    description="How posts move from idea to published in your workspace"
                />

                <Form
                    {...WorkspaceController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing }) => (
                        <>
                            <label
                                htmlFor="require_post_review"
                                className="border-border bg-card hover:border-foreground/30 flex cursor-pointer items-start gap-4 rounded-2xl border p-5 transition-colors"
                            >
                                <Checkbox
                                    id="require_post_review"
                                    checked={requireReview}
                                    onCheckedChange={(checked) =>
                                        setRequireReview(checked === true)
                                    }
                                    className="mt-0.5"
                                />
                                <input
                                    type="hidden"
                                    name="require_post_review"
                                    value={requireReview ? '1' : '0'}
                                />

                                <div className="space-y-1">
                                    <span className="text-foreground block text-sm font-semibold">
                                        Review posts before they go out
                                    </span>
                                    <span className="text-muted-foreground block text-sm">
                                        A teammate sends a post for review and
                                        an Owner or Manager approves it before
                                        it can be scheduled. Leave this off and
                                        you&apos;ll schedule straight from a
                                        draft in one tap.
                                    </span>
                                </div>
                            </label>

                            <label
                                htmlFor="insights_digest"
                                className="border-border bg-card hover:border-foreground/30 flex cursor-pointer items-start gap-4 rounded-2xl border p-5 transition-colors"
                            >
                                <Checkbox
                                    id="insights_digest"
                                    checked={!digestOff}
                                    onCheckedChange={(checked) =>
                                        setDigestOff(checked !== true)
                                    }
                                    className="mt-0.5"
                                />
                                <input
                                    type="hidden"
                                    name="insights_digest_opt_out"
                                    value={digestOff ? '1' : '0'}
                                />

                                <div className="space-y-1">
                                    <span className="text-foreground block text-sm font-semibold">
                                        Weekly insights email
                                    </span>
                                    <span className="text-muted-foreground block text-sm">
                                        Get a short Monday email with how your
                                        posts did last week and what to try
                                        next. Turn it off any time.
                                    </span>
                                </div>
                            </label>

                            <div className="flex items-center gap-3">
                                <Button type="submit" disabled={processing}>
                                    Save
                                </Button>
                                {!processing && (
                                    <span className="text-muted-foreground inline-flex items-center gap-1.5 text-sm">
                                        <CheckCircle2 className="text-lime size-4" />
                                        {requireReview
                                            ? 'Review is on'
                                            : 'One-tap scheduling'}
                                    </span>
                                )}
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

WorkspaceSettings.layout = {
    breadcrumbs: [
        {
            title: 'Workspace settings',
            href: edit(),
        },
    ],
};
