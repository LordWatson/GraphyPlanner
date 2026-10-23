import { Head } from '@inertiajs/react';
import { Download } from 'lucide-react';
import {
    MediaKitView,
    type MediaKitData,
} from '@/components/media-kit/media-kit-view';
import { PublicShell } from '@/components/public-shell';
import { Button } from '@/components/ui/button';

/**
 * Step B-8.4 — the public, read-only media kit. Renders only the already-public kit data; the owner
 * can revoke the link at any time (after which this page 404s).
 */
export default function SharedMediaKit({
    kit,
    pdfUrl,
}: {
    kit: MediaKitData;
    pdfUrl: string;
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
            </PublicShell>
        </>
    );
}
