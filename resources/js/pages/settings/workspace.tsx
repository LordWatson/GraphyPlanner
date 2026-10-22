import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { CheckCircle2 } from 'lucide-react';
import WorkspaceController from '@/actions/App/Http/Controllers/Settings/WorkspaceController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { edit } from '@/routes/workspace-settings';

type WorkspaceProps = {
    workspace: {
        name: string;
        require_post_review: boolean;
    };
};

/**
 * Step B-3.3 — the business workspace's own settings. Today just the review switch: on, posts go
 * through an internal review step before they can go out; off (the default), the owner schedules
 * in one tap. On brand: a single, clearly-explained choice, not a wall of toggles.
 */
export default function WorkspaceSettings({ workspace }: WorkspaceProps) {
    const [requireReview, setRequireReview] = useState(
        workspace.require_post_review,
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
