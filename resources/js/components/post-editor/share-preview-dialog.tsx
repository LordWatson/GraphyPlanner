import { Check, Copy, Link2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { postJson } from '@/lib/campaigns';
import { sharePreview } from '@/actions/App/Http/Controllers/PostController';

/**
 * Step B-3.4 — mint and copy a read-only partner/sponsor link for a post, with optional comments
 * and sign-off. The link reuses the review portal with brand-not-client copy and never changes the
 * post. The plain URL is shown once (hashed at rest), so it's offered to copy immediately.
 */
export function SharePreviewDialog({ postId }: { postId: number }) {
    const [open, setOpen] = useState(false);
    const [allowComments, setAllowComments] = useState(false);
    const [allowApproval, setAllowApproval] = useState(false);
    const [days, setDays] = useState('14');
    const [busy, setBusy] = useState(false);
    const [fresh, setFresh] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);

    const create = async () => {
        setBusy(true);
        const result = await postJson<{ url: string }>(
            sharePreview.url(postId),
            {
                allow_comments: allowComments || allowApproval,
                allow_approval: allowApproval,
                expires_in_days: Number(days),
            },
        );
        setBusy(false);

        if (!result.ok) {
            toast.error(result.message);

            return;
        }

        setFresh(result.data.url);
        setCopied(false);
    };

    const copy = async () => {
        if (!fresh) {
            return;
        }

        try {
            await navigator.clipboard.writeText(fresh);
            setCopied(true);
        } catch {
            toast.error('Couldn’t copy. Select the link and copy it instead.');
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (!next) {
                    setFresh(null);
                }
            }}
        >
            <DialogTrigger asChild>
                <Button type="button" variant="ghost" size="sm">
                    <Link2 className="size-4" />
                    Share preview
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Share a preview</DialogTitle>
                    <DialogDescription>
                        A read-only link to this post — for a partner or
                        sponsor, no sign-in needed. It never changes your post.
                    </DialogDescription>
                </DialogHeader>

                {fresh ? (
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="preview-link">
                            Copy it now. It won’t be shown again.
                        </Label>
                        <div className="flex gap-2">
                            <Input
                                id="preview-link"
                                readOnly
                                value={fresh}
                                onFocus={(event) =>
                                    event.currentTarget.select()
                                }
                                className="font-mono text-xs"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                onClick={copy}
                            >
                                {copied ? (
                                    <Check className="size-4" />
                                ) : (
                                    <Copy className="size-4" />
                                )}
                                {copied ? 'Copied' : 'Copy'}
                            </Button>
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-col gap-4">
                        <label className="flex items-start gap-3 text-sm">
                            <Checkbox
                                checked={allowComments}
                                onCheckedChange={(checked) =>
                                    setAllowComments(checked === true)
                                }
                                className="mt-0.5"
                            />
                            <span>
                                <span className="text-foreground block font-medium">
                                    Let them comment
                                </span>
                                <span className="text-muted-foreground">
                                    They can leave a note you’ll see on the
                                    post.
                                </span>
                            </span>
                        </label>
                        <label className="flex items-start gap-3 text-sm">
                            <Checkbox
                                checked={allowApproval}
                                onCheckedChange={(checked) =>
                                    setAllowApproval(checked === true)
                                }
                                className="mt-0.5"
                            />
                            <span>
                                <span className="text-foreground block font-medium">
                                    Let them sign off
                                </span>
                                <span className="text-muted-foreground">
                                    Adds approve / request-changes buttons.
                                    Their choice is recorded — it never posts
                                    for you.
                                </span>
                            </span>
                        </label>

                        <div className="flex items-end gap-2">
                            <div className="grid flex-1 gap-1.5">
                                <Label htmlFor="preview-days">
                                    Works for (days)
                                </Label>
                                <Input
                                    id="preview-days"
                                    type="number"
                                    min={1}
                                    max={90}
                                    value={days}
                                    onChange={(event) =>
                                        setDays(event.target.value)
                                    }
                                />
                            </div>
                            <Button
                                type="button"
                                onClick={create}
                                disabled={
                                    busy ||
                                    Number(days) < 1 ||
                                    Number(days) > 90
                                }
                            >
                                <Link2 className="size-4" />
                                Make a link
                            </Button>
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
