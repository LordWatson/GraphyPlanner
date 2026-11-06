import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { Download, Handshake } from 'lucide-react';
import {
    MediaKitView,
    type MediaKitData,
} from '@/components/media-kit/media-kit-view';
import InputError from '@/components/input-error';
import { Panel } from '@/components/page';
import { PublicShell } from '@/components/public-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

/**
 * Step B-8.4 / CG-5.3 — the public, read-only media kit, with an optional "work with me" form. Renders only the already-public kit data; the owner
 * can revoke the link at any time (after which this page 404s).
 */
export default function SharedMediaKit({
    kit,
    pdfUrl,
    enquiryUrl = null,
}: {
    kit: MediaKitData;
    pdfUrl: string;
    /** Step CG-5.3: set only while the owner has enquiries switched on. */
    enquiryUrl?: string | null;
}) {
    return (
        <>
            <Head title={`${kit.brand.name} — media kit`}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <PublicShell width="lg" context={kit.brand.name}>
                <div className="mb-6 flex justify-end">
                    <a href={pdfUrl} target="_blank" rel="noopener noreferrer">
                        <Button variant="outline">
                            <Download className="size-4" />
                            Download PDF
                        </Button>
                    </a>
                </div>
                <MediaKitView kit={kit} />

                {enquiryUrl && (
                    <div className="mt-5">
                        <EnquiryForm
                            action={enquiryUrl}
                            brand={kit.brand.name}
                        />
                    </div>
                )}
            </PublicShell>
        </>
    );
}

/**
 * Step CG-5.3 — a sponsor's enquiry. It becomes a draft deal on the creator's tracker; nothing is
 * sent back on their behalf, and the answer is the same whatever we do with it.
 *
 * `website` is a honeypot: it is hidden from people and left empty by them, so a filled one marks
 * a bot. Named plausibly on purpose — a bot that reads labels fills it in.
 */
function EnquiryForm({ action, brand }: { action: string; brand: string }) {
    const [sent, setSent] = useState(false);

    return (
        <Panel
            icon={Handshake}
            eyebrow="Work together"
            title={`Message ${brand}`}
        >
            {sent ? (
                <p className="text-sm">
                    Thanks — your message is with them. They will be in touch
                    from their own inbox.
                </p>
            ) : (
                <Form
                    action={action}
                    method="post"
                    options={{ preserveScroll: true }}
                    onSuccess={() => setSent(true)}
                    className="grid gap-4 sm:grid-cols-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="enquiry-name">Your name</Label>
                                <Input
                                    id="enquiry-name"
                                    name="name"
                                    required
                                    maxLength={120}
                                    autoComplete="name"
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="enquiry-email">Email</Label>
                                <Input
                                    id="enquiry-email"
                                    name="email"
                                    type="email"
                                    required
                                    maxLength={255}
                                    autoComplete="email"
                                />
                                <InputError message={errors.email} />
                            </div>
                            <div className="flex flex-col gap-1.5 sm:col-span-2">
                                <Label htmlFor="enquiry-company">
                                    Brand or company
                                </Label>
                                <Input
                                    id="enquiry-company"
                                    name="company"
                                    maxLength={120}
                                />
                                <InputError message={errors.company} />
                            </div>
                            <div className="flex flex-col gap-1.5 sm:col-span-2">
                                <Label htmlFor="enquiry-message">
                                    What do you have in mind?
                                </Label>
                                <Textarea
                                    id="enquiry-message"
                                    name="message"
                                    rows={4}
                                    maxLength={2000}
                                    placeholder="Campaign, timings, budget — whatever you have so far."
                                />
                                <InputError message={errors.message} />
                            </div>

                            {/* Honeypot: hidden from people, irresistible to bots. */}
                            <input
                                type="text"
                                name="website"
                                tabIndex={-1}
                                autoComplete="off"
                                aria-hidden
                                className="hidden"
                            />

                            <div className="sm:col-span-2">
                                <Button type="submit" disabled={processing}>
                                    Send enquiry
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}
        </Panel>
    );
}
