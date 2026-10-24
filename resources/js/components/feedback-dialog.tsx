import { useForm, usePage } from '@inertiajs/react';
import { MessageSquareHeart } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { Auth } from '@/types';
import { store } from '@/routes/feedback';

const STAFF_ROLES = ['owner', 'strategist', 'designer', 'viewer'];

/**
 * Step M-8.2 — the in-app "Send feedback" panel. Mounted in the studio sidebar footer for internal
 * staff only (never client reviewers or the org-less super admin). Captures the page the sender was
 * on so the platform team can see which screen prompted the note; the body is free text.
 */
export function FeedbackDialog() {
    const page = usePage<{ auth: Auth; preview?: boolean }>();
    const role = page.props.auth.user?.role;
    const [open, setOpen] = useState(false);

    const form = useForm<{ message: string; page: string }>({
        message: '',
        page: '',
    });

    // Staff only, and never in the read-only preview fixture (its writes are blocked anyway).
    if (!role || !STAFF_ROLES.includes(role) || page.props.preview) {
        return null;
    }

    const onOpenChange = (next: boolean) => {
        setOpen(next);

        if (next) {
            // Record where they were when they opened the panel.
            form.setData('page', page.url);
        } else {
            form.reset();
            form.clearErrors();
        }
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.post(store().url, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
                toast.success('Thanks — your feedback is on its way to us.');
            },
        });
    };

    return (
        <>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        onClick={() => onOpenChange(true)}
                        tooltip="Send feedback"
                        className="text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                        data-test="send-feedback-trigger"
                    >
                        <MessageSquareHeart />
                        <span>Send feedback</span>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>

            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent>
                    <form onSubmit={submit} className="grid gap-5">
                        <DialogHeader>
                            <span className="eyebrow text-primary">
                                — Your feedback
                            </span>
                            <DialogTitle>Tell us what you think.</DialogTitle>
                            <DialogDescription>
                                Found a snag, or have an idea? Send it straight
                                to the Alima team. We read every note.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-2">
                            <Label
                                htmlFor="feedback-message"
                                className="sr-only"
                            >
                                Your feedback
                            </Label>
                            <Textarea
                                id="feedback-message"
                                name="message"
                                value={form.data.message}
                                onChange={(event) =>
                                    form.setData('message', event.target.value)
                                }
                                placeholder="What's working, what isn't, what would help…"
                                rows={5}
                                autoFocus
                                required
                            />
                            <InputError message={form.errors.message} />
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => onOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing && <Spinner />}
                                Send feedback
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
