import { Head } from '@inertiajs/react';
import {
    BIO_THEME_CLASSES,
    BioPageView,
    type BioPagePayload,
} from '@/components/bio/bio-page-view';
import { cn } from '@/lib/utils';

/**
 * Step CG-3.1 — the public link in bio at `/@{handle}`. The creator's page, not Alima's: their
 * theme owns the whole canvas, with one quiet credit at the foot. Unpublished pages never reach
 * here (the controller 404s), and only visible blocks are in the payload.
 */
export default function PublicBioPage({ page }: { page: BioPagePayload }) {
    return (
        <div
            className={cn(
                'flex min-h-screen flex-col',
                BIO_THEME_CLASSES[page.theme] ?? BIO_THEME_CLASSES.cream,
            )}
        >
            <Head title={`${page.title ?? page.brand_name} (@${page.handle})`}>
                <meta
                    name="description"
                    content={page.bio ?? `Links from ${page.brand_name}.`}
                />
            </Head>

            <BioPageView page={page} className="flex-1" />

            <footer className="flex justify-center pb-10 font-mono text-[11px] tracking-wide uppercase opacity-60">
                <a href="/" className="hover:opacity-100">
                    Made with Alima
                </a>
            </footer>
        </div>
    );
}
