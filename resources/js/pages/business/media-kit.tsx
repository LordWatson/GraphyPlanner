import { Deferred, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    Check,
    Copy,
    Download,
    Eye,
    EyeOff,
    FileText,
    Link2,
    Plus,
    Trash2,
} from 'lucide-react';
import {
    MediaKitView,
    type MediaKitData,
} from '@/components/media-kit/media-kit-view';
import { ChapterHero, Page, Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { index, pdf, revoke, share, update } from '@/routes/business/media-kit';

type Rate = { label: string; amount_minor: number };

type Kit = {
    bio: string | null;
    /** Step CG-5.3: the optional rate card and the enquiry-form switch. */
    rates: Rate[];
    enquiries_enabled: boolean;
    is_public: boolean;
    share_url: string | null;
    shared_at: string | null;
};

export default function BusinessMediaKit({
    kit,
    currency,
    publicPreview,
    canManage,
}: {
    kit: Kit;
    currency: string;
    publicPreview?: MediaKitData;
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
                    <SponsorCard kit={kit} currency={currency} />
                    <ShareCard kit={kit} />
                </div>
            )}

            <Panel eyebrow="Preview" title="What people see">
                <Deferred
                    data="publicPreview"
                    fallback={<Skeleton className="h-72 rounded-2xl" />}
                >
                    {publicPreview ? (
                        <MediaKitView kit={publicPreview} />
                    ) : (
                        <Skeleton className="h-72 rounded-2xl" />
                    )}
                </Deferred>
            </Panel>
        </Page>
    );
}

/**
 * Step B-8.4 / CG-5.3 — what the owner writes for sponsors: the bio, an optional rate card, and
 * whether the public kit takes enquiries (each one becomes a draft deal on `/deals`).
 *
 * Rates are typed in whole currency and submitted in minor units, because that is the only form
 * money takes server-side.
 */
function SponsorCard({ kit, currency }: { kit: Kit; currency: string }) {
    const [bio, setBio] = useState(kit.bio ?? '');
    const [enquiries, setEnquiries] = useState(kit.enquiries_enabled);
    const [rates, setRates] = useState<{ label: string; amount: string }[]>(
        () =>
            kit.rates.map((rate) => ({
                label: rate.label,
                amount: (rate.amount_minor / 100).toString(),
            })),
    );
    const [saving, setSaving] = useState(false);

    const setRate = (
        index: number,
        patch: Partial<{ label: string; amount: string }>,
    ) =>
        setRates((current) =>
            current.map((rate, i) =>
                i === index ? { ...rate, ...patch } : rate,
            ),
        );

    const save = () => {
        setSaving(true);
        router.put(
            update().url,
            {
                bio: bio || null,
                enquiries_enabled: enquiries,
                rates: rates
                    .filter(
                        (rate) =>
                            rate.label.trim() !== '' && Number(rate.amount) > 0,
                    )
                    .map((rate) => ({
                        label: rate.label.trim(),
                        amount_minor: Math.round(Number(rate.amount) * 100),
                    })),
            },
            { preserveScroll: true, onFinish: () => setSaving(false) },
        );
    };

    return (
        <Panel icon={FileText} eyebrow="About" title="For sponsors">
            <div className="flex flex-col gap-4">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="kit-bio">Your bio</Label>
                    <Textarea
                        id="kit-bio"
                        value={bio}
                        onChange={(event) => setBio(event.target.value)}
                        rows={5}
                        maxLength={1000}
                        placeholder="A short intro for sponsors — who you are, who you reach, and what you make."
                    />
                </div>

                <div className="flex flex-col gap-2">
                    <Label>Rates ({currency}, optional)</Label>
                    {rates.map((rate, index) => (
                        <div key={index} className="flex items-center gap-2">
                            <Input
                                value={rate.label}
                                onChange={(event) =>
                                    setRate(index, {
                                        label: event.target.value,
                                    })
                                }
                                maxLength={60}
                                placeholder="e.g. Instagram Reel"
                                aria-label="What the rate covers"
                            />
                            <Input
                                value={rate.amount}
                                onChange={(event) =>
                                    setRate(index, {
                                        amount: event.target.value,
                                    })
                                }
                                type="number"
                                min={0}
                                step="0.01"
                                className="w-28"
                                aria-label="Rate"
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                aria-label={`Remove ${rate.label || 'rate'}`}
                                onClick={() =>
                                    setRates(
                                        rates.filter((_, i) => i !== index),
                                    )
                                }
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        </div>
                    ))}
                    {rates.length < 12 && (
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setRates([
                                        ...rates,
                                        { label: '', amount: '' },
                                    ])
                                }
                            >
                                <Plus className="size-4" />
                                Add a rate
                            </Button>
                        </div>
                    )}
                    <p className="text-muted-foreground text-sm">
                        Leave this empty if you would rather talk about money by
                        email.
                    </p>
                </div>

                <label className="flex items-start gap-3 text-sm">
                    <Checkbox
                        checked={enquiries}
                        onCheckedChange={(checked) =>
                            setEnquiries(checked === true)
                        }
                        className="mt-0.5"
                    />
                    <span>
                        Let sponsors message me from my kit.
                        <span className="text-muted-foreground block">
                            Each enquiry lands on your deals page as a draft —
                            nothing is sent on your behalf.
                        </span>
                    </span>
                </label>

                <div>
                    <Button type="button" disabled={saving} onClick={save}>
                        Save
                    </Button>
                </div>
            </div>
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
