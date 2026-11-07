import { Head, Link, router } from '@inertiajs/react';
import { Loader2, Mails, Send, Sparkles, Trash2, Upload } from 'lucide-react';
import { useState } from 'react';
import { ChapterHero, EmptyState, Page, Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { http } from '@/lib/http';
import {
    draft as draftBroadcast,
    importMethod as importContacts,
} from '@/routes/business/audience';
import { store as sendBroadcast } from '@/routes/business/audience/broadcasts';
import { destroy as destroyContact } from '@/routes/business/audience/contacts';
import { edit as editPlan } from '@/routes/plan';

type Contact = {
    id: number;
    email: string;
    name: string | null;
    source: string;
    source_label: string;
    status: 'confirmed' | 'pending' | 'unsubscribed';
    created_at: string | null;
};

type Broadcast = {
    id: number;
    subject: string;
    segment: string;
    recipients: number;
    sent_at: string | null;
};

/**
 * Phase GTM-2 — the creator's email list. Three things on one page: who is on it, bringing a list
 * in, and writing one email to a segment.
 *
 * Consent is shown, never implied. An unconfirmed contact is labelled as such and is in no segment,
 * so there is no way to compose an email here that reaches somebody who hasn't asked for it.
 */
export default function BusinessAudience({
    contacts,
    totals,
    showing,
    max_contacts: maxContacts,
    broadcasts,
    products,
    entitled,
    canManage,
}: {
    contacts: Contact[];
    totals: { total: number; confirmed: number; unsubscribed: number };
    showing: number;
    max_contacts: number;
    broadcasts: Broadcast[];
    products: { id: number; title: string }[];
    entitled: boolean;
    canManage: boolean;
}) {
    const [csv, setCsv] = useState('');
    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');
    const [segment, setSegment] = useState('all');
    const [productId, setProductId] = useState('');
    const [notes, setNotes] = useState('');
    const [drafting, setDrafting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const draftWithAi = async () => {
        setDrafting(true);

        const result = await http.post<{ text: string }>(
            draftBroadcast().url,
            { notes },
            { toastOnError: true },
        );

        setDrafting(false);

        if (result.ok && result.data.text) {
            setBody(result.data.text);
        }
    };

    const send = () =>
        router.post(
            sendBroadcast().url,
            {
                subject,
                body,
                segment,
                product_id: productId === '' ? null : Number(productId),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setErrors({});
                    setSubject('');
                    setBody('');
                    setNotes('');
                },
                onError: (bag) => setErrors(bag as Record<string, string>),
            },
        );

    return (
        <Page>
            <Head title="Audience" />

            <ChapterHero
                eyebrow="Your list"
                title={
                    <>
                        Your <em>audience</em>.
                    </>
                }
                description="Your buyers and everyone who signed up on your page. Nobody receives marketing until they have confirmed it themselves."
            />

            {!entitled && (
                <EmptyState
                    icon={Sparkles}
                    title="Email is on Creator Pro."
                    description="Your contacts are collected and yours either way — upgrading is what lets you email them."
                    action={
                        <Button variant="brand" asChild>
                            <Link href={editPlan()}>See plans</Link>
                        </Button>
                    }
                />
            )}

            <Panel
                eyebrow="Contacts"
                title={`${totals.confirmed} confirmed of ${totals.total}`}
            >
                {contacts.length === 0 ? (
                    <EmptyState
                        compact
                        icon={Mails}
                        title="Nobody on the list yet."
                        description="Add an email sign-up block to your link in bio, or import a list you already have."
                    />
                ) : (
                    <ul className="divide-border/60 flex flex-col divide-y">
                        {contacts.map((contact) => (
                            <li
                                key={contact.id}
                                className="flex flex-wrap items-center gap-3 py-3"
                            >
                                <StatusPill
                                    kind="audienceContact"
                                    value={contact.status}
                                />
                                <span className="font-medium">
                                    {contact.email}
                                </span>
                                {contact.name && (
                                    <span className="text-muted-foreground text-sm">
                                        {contact.name}
                                    </span>
                                )}
                                <span className="text-muted-foreground text-sm">
                                    {contact.source_label}
                                </span>
                                <span className="flex-1" />
                                {canManage && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        aria-label={`Remove ${contact.email}`}
                                        onClick={() =>
                                            router.delete(
                                                destroyContact({
                                                    contact: contact.id,
                                                }).url,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {showing < totals.total && (
                    <p className="text-muted-foreground text-[13px]">
                        Showing the newest {maxContacts} of {totals.total}.
                    </p>
                )}

                {totals.unsubscribed > 0 && (
                    <p className="text-muted-foreground text-[13px]">
                        {totals.unsubscribed} unsubscribed. They stay on the
                        list so an import can never bring them back.
                    </p>
                )}
            </Panel>

            {canManage && (
                <Panel eyebrow="Import" title="Bring a list in">
                    <p className="text-muted-foreground text-sm">
                        One address per line, or{' '}
                        <code className="text-[13px]">email,name</code>.
                        Everyone imported is asked to confirm first — an import
                        is never permission on its own.
                    </p>
                    <Textarea
                        value={csv}
                        onChange={(event) => setCsv(event.target.value)}
                        rows={5}
                        placeholder={'jo@example.com,Jo\nsam@example.com'}
                    />
                    <div>
                        <Button
                            variant="outline"
                            disabled={csv.trim() === ''}
                            onClick={() =>
                                router.post(
                                    importContacts().url,
                                    { csv },
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setCsv(''),
                                    },
                                )
                            }
                        >
                            <Upload className="size-4" />
                            Import and ask them to confirm
                        </Button>
                    </div>
                </Panel>
            )}

            {canManage && entitled && (
                <Panel eyebrow="Broadcast" title="Write to your list">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="broadcast-segment">Who</Label>
                        <Select value={segment} onValueChange={setSegment}>
                            <SelectTrigger id="broadcast-segment">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    Everyone who has confirmed
                                </SelectItem>
                                <SelectItem value="product">
                                    Buyers of one product
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        {errors.segment && (
                            <p className="text-destructive text-sm">
                                {errors.segment}
                            </p>
                        )}
                    </div>

                    {segment === 'product' && (
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="broadcast-product">
                                Which product
                            </Label>
                            <Select
                                value={productId}
                                onValueChange={setProductId}
                            >
                                <SelectTrigger id="broadcast-product">
                                    <SelectValue
                                        placeholder={
                                            products.length === 0
                                                ? 'Nothing on sale yet'
                                                : 'Pick a product'
                                        }
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    {products.map((product) => (
                                        <SelectItem
                                            key={product.id}
                                            value={product.id.toString()}
                                        >
                                            {product.title}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.product_id && (
                                <p className="text-destructive text-sm">
                                    {errors.product_id}
                                </p>
                            )}
                        </div>
                    )}

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="broadcast-subject">Subject</Label>
                        <Input
                            id="broadcast-subject"
                            value={subject}
                            onChange={(event) => setSubject(event.target.value)}
                            maxLength={180}
                        />
                        {errors.subject && (
                            <p className="text-destructive text-sm">
                                {errors.subject}
                            </p>
                        )}
                    </div>

                    <div className="border-primary/30 bg-primary/5 flex flex-col gap-2 rounded-xl border p-4">
                        <Label htmlFor="broadcast-notes">
                            A few words about this email
                        </Label>
                        <Input
                            id="broadcast-notes"
                            value={notes}
                            onChange={(event) => setNotes(event.target.value)}
                            placeholder="e.g. new preset pack out Friday, 20% off for a week"
                        />
                        <div>
                            <Button
                                variant="secondary"
                                onClick={draftWithAi}
                                disabled={drafting || notes.trim() === ''}
                            >
                                {drafting ? (
                                    <Loader2 className="size-4 animate-spin" />
                                ) : (
                                    <Sparkles className="size-4" />
                                )}
                                Draft with AI
                            </Button>
                        </div>
                        <p className="text-muted-foreground text-[13px]">
                            It fills the message below in your brand voice. You
                            still read it, and you still press send.
                        </p>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="broadcast-body">Message</Label>
                        <Textarea
                            id="broadcast-body"
                            value={body}
                            onChange={(event) => setBody(event.target.value)}
                            rows={8}
                        />
                        {errors.body && (
                            <p className="text-destructive text-sm">
                                {errors.body}
                            </p>
                        )}
                    </div>

                    <div>
                        <Button
                            variant="brand"
                            onClick={send}
                            disabled={
                                subject.trim() === '' || body.trim() === ''
                            }
                        >
                            <Send className="size-4" />
                            Send it
                        </Button>
                    </div>
                </Panel>
            )}

            {broadcasts.length > 0 && (
                <Panel eyebrow="Sent" title="What has gone out">
                    <ul className="divide-border/60 flex flex-col divide-y">
                        {broadcasts.map((broadcast) => (
                            <li
                                key={broadcast.id}
                                className="flex flex-wrap items-center gap-3 py-3"
                            >
                                <span className="font-medium">
                                    {broadcast.subject}
                                </span>
                                <span className="text-muted-foreground text-sm">
                                    {broadcast.recipients} recipients
                                </span>
                                <span className="flex-1" />
                                {broadcast.sent_at && (
                                    <span className="text-muted-foreground text-sm">
                                        {new Date(
                                            broadcast.sent_at,
                                        ).toLocaleDateString()}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}
        </Page>
    );
}
