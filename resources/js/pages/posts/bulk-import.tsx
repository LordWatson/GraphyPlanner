import { Head, router } from '@inertiajs/react';
import { Download, FileUp, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { Page, PageHeader, Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import { http } from '@/lib/http';
import { index as clientPosts } from '@/routes/clients/posts';
import { bulkStore } from '@/routes/clients/posts';
import {
    preview as previewRoute,
    template,
} from '@/routes/clients/posts/bulk-import';
import { cn } from '@/lib/utils';

type NamedClient = { id: number; name: string | null };
type Account = { handle: string; platform: string };

type PreviewRow = {
    row: number;
    caption: string;
    channels: string[];
    date: string | null;
    time: string | null;
    format: string | null;
};
type RowError = { row: number; messages: string[] };
type PreviewResult = {
    rows: PreviewRow[];
    errors: RowError[];
    valid_count: number;
    error_count: number;
};

type Props = {
    client: NamedClient;
    columns: string[];
    accounts: Account[];
    campaignCodes: string[];
};

/**
 * Step M-5.8 (Phase 10) — upload a CSV of posts for a client, preview the parsed rows with per-row
 * errors, then create the whole batch as drafts. All-or-nothing: a flagged row blocks the import.
 */
export default function BulkImport({
    client,
    columns,
    accounts,
    campaignCodes,
}: Props) {
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<PreviewResult | null>(null);
    const [previewing, setPreviewing] = useState(false);
    const [creating, setCreating] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);

    const pickFile = (next: File | null) => {
        setFile(next);
        setPreview(null);
    };

    const runPreview = async () => {
        if (!file) return;
        setPreviewing(true);
        const body = new FormData();
        body.append('file', file);
        const result = await http.post<PreviewResult>(
            previewRoute(client.id).url,
            body,
            { toastOnError: true },
        );
        setPreviewing(false);
        if (result.ok) setPreview(result.data);
    };

    const create = () => {
        if (
            !file ||
            !preview ||
            preview.error_count > 0 ||
            preview.valid_count === 0
        ) {
            return;
        }
        router.post(
            bulkStore(client.id).url,
            { file },
            {
                forceFormData: true,
                onStart: () => setCreating(true),
                onFinish: () => setCreating(false),
                onError: () => toast.error('The import couldn’t be processed.'),
            },
        );
    };

    const canCreate =
        preview !== null &&
        preview.error_count === 0 &&
        preview.valid_count > 0;

    return (
        <>
            <Head title={`Bulk import — ${client.name ?? 'Client'}`} />
            <Page>
                <PageHeader
                    eyebrow={`Bulk import — ${client.name ?? 'Client'}`}
                    title={
                        <>
                            Import a <em>month</em> at once
                        </>
                    }
                    description="Upload a CSV and we’ll create a draft for each row — ready to review and schedule. Nothing is posted automatically."
                    actions={
                        <Button variant="outline" asChild>
                            <a href={template(client.id).url}>
                                <Download />
                                CSV template
                            </a>
                        </Button>
                    }
                />

                <Panel
                    eyebrow="01"
                    title="Upload your CSV"
                    description="One row per post. Download the template above if you’re starting fresh."
                    bodyClassName="flex flex-col gap-4"
                >
                    <label
                        className={cn(
                            'border-border hover:border-primary/50 hover:bg-muted/40 flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed px-6 py-10 text-center transition-colors',
                        )}
                    >
                        <FileUp className="text-muted-foreground size-6" />
                        <span className="text-sm font-medium">
                            {file ? file.name : 'Choose a CSV file'}
                        </span>
                        <span className="text-muted-foreground text-xs">
                            CSV, up to 2 MB
                        </span>
                        <input
                            ref={inputRef}
                            type="file"
                            accept=".csv,text/csv,text/plain"
                            className="sr-only"
                            onChange={(e) =>
                                pickFile(e.target.files?.[0] ?? null)
                            }
                        />
                    </label>
                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            onClick={runPreview}
                            disabled={!file || previewing}
                        >
                            <Upload />
                            {previewing ? 'Reading…' : 'Preview'}
                        </Button>
                        {file && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => {
                                    pickFile(null);
                                    if (inputRef.current)
                                        inputRef.current.value = '';
                                }}
                            >
                                Clear
                            </Button>
                        )}
                    </div>
                </Panel>

                {preview && (
                    <Panel
                        eyebrow="02"
                        title="Preview"
                        description={
                            preview.error_count > 0
                                ? 'Fix the flagged rows in your CSV and upload again — nothing is imported until every row is clean.'
                                : `${preview.valid_count} post${preview.valid_count === 1 ? '' : 's'} ready to import as drafts.`
                        }
                        bodyClassName="flex flex-col gap-4"
                    >
                        {preview.errors.length > 0 && (
                            <ul className="border-destructive/30 bg-destructive/5 flex flex-col gap-2 rounded-lg border p-4 text-sm">
                                {preview.errors.map((error) => (
                                    <li key={error.row}>
                                        <span className="text-destructive font-medium">
                                            Row {error.row}:
                                        </span>{' '}
                                        {error.messages.join(' ')}
                                    </li>
                                ))}
                            </ul>
                        )}

                        {preview.rows.length > 0 && (
                            <div className="overflow-x-auto rounded-lg border">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-muted-foreground eyebrow text-left">
                                        <tr>
                                            <th className="px-3 py-2">Row</th>
                                            <th className="px-3 py-2">
                                                Caption
                                            </th>
                                            <th className="px-3 py-2">
                                                Channels
                                            </th>
                                            <th className="px-3 py-2">When</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {preview.rows.map((row) => (
                                            <tr
                                                key={row.row}
                                                className="border-t align-top"
                                            >
                                                <td className="text-muted-foreground px-3 py-2 font-mono text-xs">
                                                    {row.row}
                                                </td>
                                                <td className="max-w-xs px-3 py-2">
                                                    <span className="line-clamp-2">
                                                        {row.caption || (
                                                            <span className="text-muted-foreground italic">
                                                                No caption
                                                            </span>
                                                        )}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-2">
                                                    {row.channels.join(', ')}
                                                </td>
                                                <td className="text-muted-foreground px-3 py-2 whitespace-nowrap">
                                                    {row.date
                                                        ? `${row.date}${row.time ? ` · ${row.time}` : ''}`
                                                        : 'No date'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        <div className="flex items-center gap-3">
                            <Button
                                type="button"
                                onClick={create}
                                disabled={!canCreate || creating}
                            >
                                {creating
                                    ? 'Importing…'
                                    : `Create ${preview.valid_count} draft${preview.valid_count === 1 ? '' : 's'}`}
                            </Button>
                            <Button variant="outline" asChild>
                                <a href={clientPosts(client.id).url}>Cancel</a>
                            </Button>
                        </div>
                    </Panel>
                )}

                <Panel
                    eyebrow="Guide"
                    title="Moving from another tool"
                    description="Export your scheduled posts to CSV, then map them to these columns."
                    bodyClassName="flex flex-col gap-5 text-sm"
                >
                    <div>
                        <p className="mb-2 font-medium">Columns</p>
                        <div className="flex flex-wrap gap-1.5">
                            {columns.map((column) => (
                                <code
                                    key={column}
                                    className="bg-muted rounded px-1.5 py-0.5 font-mono text-xs"
                                >
                                    {column}
                                </code>
                            ))}
                        </div>
                        <p className="text-muted-foreground mt-2 text-xs">
                            Only <code>accounts</code> is required. List one or
                            more handles separated by <code>;</code>. Dates are{' '}
                            <code>YYYY-MM-DD</code> and times <code>HH:MM</code>{' '}
                            (24-hour), in each account’s local time. Hashtags
                            are space- or comma-separated.
                        </p>
                    </div>
                    <div>
                        <p className="mb-2 font-medium">
                            This client’s accounts
                        </p>
                        {accounts.length === 0 ? (
                            <p className="text-muted-foreground text-xs">
                                No accounts connected yet — connect one before
                                importing.
                            </p>
                        ) : (
                            <ul className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                {accounts.map((account) => (
                                    <li key={account.handle}>
                                        <span className="text-foreground font-mono">
                                            {account.handle}
                                        </span>{' '}
                                        · {account.platform}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                    {campaignCodes.length > 0 && (
                        <div>
                            <p className="mb-2 font-medium">
                                Campaign codes (optional)
                            </p>
                            <p className="text-muted-foreground text-xs">
                                Put one of these in the <code>campaign</code>{' '}
                                column to attach a row to a campaign:{' '}
                                {campaignCodes.map((code, i) => (
                                    <span key={code}>
                                        {i > 0 && ', '}
                                        <code className="bg-muted rounded px-1 py-0.5 font-mono">
                                            {code}
                                        </code>
                                    </span>
                                ))}
                                .
                            </p>
                        </div>
                    )}
                </Panel>
            </Page>
        </>
    );
}
