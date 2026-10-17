import { Head, Link, router } from '@inertiajs/react';
import { LifeBuoy, LogOut } from 'lucide-react';
import { PublicShell, PublicTitle } from '@/components/public-shell';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';

type Props = {
    organization: { name: string };
    reason: string | null;
    supportEmail: string | null;
};

/**
 * Step 15.3 — what every member of a suspended organization sees (rendered by
 * `EnsureOrganizationNotSuspended` for any page they request). No app chrome: just why, who to
 * talk to, and a way to sign out. Their work is safe — suspension deletes nothing.
 */
export default function OrganizationSuspended({
    organization,
    reason,
    supportEmail,
}: Props) {
    return (
        <>
            <Head title="Workspace paused" />
            <PublicShell context={organization.name}>
                <PublicTitle
                    kicker="Workspace paused"
                    title={
                        <>
                            This workspace is <em>on hold</em>.
                        </>
                    }
                >
                    {organization.name} has been suspended, so nobody on the
                    team can sign in to it for now. Nothing has been deleted —
                    your clients, posts and invoices are exactly where you left
                    them.
                </PublicTitle>

                {reason && (
                    <figure className="bg-ink text-ink-foreground rounded-2xl p-6">
                        <span className="eyebrow text-cream/60">
                            Note from our team
                        </span>
                        <blockquote className="mt-3 text-[17px] leading-relaxed font-light text-pretty">
                            <span aria-hidden className="text-lime mr-1">
                                “
                            </span>
                            {reason}
                        </blockquote>
                    </figure>
                )}

                <section className="border-border/80 bg-card flex flex-col gap-4 rounded-2xl border p-6 shadow-sm">
                    <div className="flex items-start gap-3">
                        <span className="bg-accent text-accent-foreground flex size-9 shrink-0 items-center justify-center rounded-full">
                            <LifeBuoy className="size-[18px]" />
                        </span>
                        <div className="flex flex-col gap-1">
                            <h2 className="text-[17px] font-semibold tracking-[-0.01em]">
                                Got a question? Let’s talk.
                            </h2>
                            <p className="text-muted-foreground text-sm text-pretty">
                                Your workspace owner can get it running again by
                                contacting our support team.
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        {supportEmail && (
                            <Button asChild size="lg" className="sm:flex-1">
                                <a
                                    href={`mailto:${supportEmail}?subject=${encodeURIComponent(`Suspended workspace: ${organization.name}`)}`}
                                >
                                    Contact support
                                </a>
                            </Button>
                        )}
                        <Button
                            asChild
                            variant="outline"
                            size="lg"
                            className="sm:flex-1"
                        >
                            <Link
                                href={logout()}
                                as="button"
                                onClick={() => router.flushAll()}
                                data-test="suspended-logout-button"
                            >
                                <LogOut />
                                Log out
                            </Link>
                        </Button>
                    </div>
                    {supportEmail && (
                        <p className="text-muted-foreground font-mono text-xs">
                            {supportEmail}
                        </p>
                    )}
                </section>
            </PublicShell>
        </>
    );
}
