import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import ClientPortalLayout from '@/layouts/client-portal/client-portal-layout';

type PostData = {
    id: number;
    status: string;
    status_label: string;
    master_caption: string | null;
};

/**
 * Step 6.3 — minimal scoped post view proving the portal's isolation guarantee. Approvals,
 * comments, and the full editor-adjacent detail view land in Step 6.5.
 */
export default function PortalPostShow({ post }: { post: PostData }) {
    return (
        <ClientPortalLayout title={`Post #${post.id}`}>
            <Heading title={`Post #${post.id}`} />

            <div className="mt-6 rounded-xl border border-border bg-card p-6 shadow-lg shadow-black/[0.03]">
                <Badge variant="outline">{post.status_label}</Badge>
                {post.master_caption && (
                    <p className="mt-4 text-sm leading-relaxed whitespace-pre-wrap">{post.master_caption}</p>
                )}
            </div>
        </ClientPortalLayout>
    );
}
