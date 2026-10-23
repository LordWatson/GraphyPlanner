import { router } from '@inertiajs/react';
import { Cloud, FileImage, Loader2, RefreshCw, Unplug } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { http } from '@/lib/http';
import { disconnect as disconnectRoute } from '@/routes/design-imports';
import {
    files as filesRoute,
    importMethod,
} from '@/routes/clients/design-imports';

export type DesignImportProviderConfig = {
    value: string;
    label: string;
    connected: boolean;
    connection_id: number | null;
    connect_url: string;
};

export type DesignImportsConfig = {
    can_import: boolean;
    can_connect: boolean;
    providers: DesignImportProviderConfig[];
};

type RemoteFile = {
    id: string;
    name: string;
    mime_type: string | null;
    thumbnail_url: string | null;
    size: number | null;
};

type FilesResponse = {
    connected: boolean;
    needs_reconnect?: boolean;
    files: RemoteFile[];
};

/**
 * Step M-5.9 — pull a design or media file in from Canva / Google Drive / Dropbox. Connected
 * sources list their files (lazily); importing runs the file through the normal private asset
 * pipeline. A dead connection shows a reconnect prompt instead of an error.
 */
export function DesignImportDialog({
    clientId,
    config,
    open,
    onOpenChange,
    campaignId,
}: {
    clientId: number;
    config: DesignImportsConfig;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    campaignId?: number;
}) {
    const [active, setActive] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [files, setFiles] = useState<RemoteFile[]>([]);
    const [needsReconnect, setNeedsReconnect] = useState(false);
    const [importingId, setImportingId] = useState<string | null>(null);

    const browse = async (provider: string) => {
        setActive(provider);
        setLoading(true);
        setNeedsReconnect(false);
        setFiles([]);

        const result = await http.get<FilesResponse>(
            `${filesRoute(clientId).url}?provider=${provider}`,
        );
        setLoading(false);

        if (!result.ok) {
            if (result.status === 409) setNeedsReconnect(true);
            else toast.error(result.message);
            return;
        }

        setFiles(result.data.files);
    };

    const runImport = (provider: string, file: RemoteFile) => {
        setImportingId(file.id);
        router.post(
            importMethod(clientId).url,
            { provider, file_id: file.id, campaign_id: campaignId ?? null },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['stats'],
                onFinish: () => setImportingId(null),
            },
        );
    };

    const disconnect = (id: number) => {
        router.delete(disconnectRoute(id).url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setActive(null);
                setFiles([]);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Import a design</DialogTitle>
                    <DialogDescription>
                        Pull media in from Canva, Google Drive or Dropbox. It
                        lands in this client’s library like any other asset.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-3">
                    {config.providers.map((provider) => (
                        <div
                            key={provider.value}
                            className="rounded-xl border p-4"
                        >
                            <div className="flex items-center justify-between gap-3">
                                <span className="flex items-center gap-2 font-medium">
                                    <Cloud className="text-muted-foreground size-4" />
                                    {provider.label}
                                </span>
                                {provider.connected ? (
                                    <div className="flex items-center gap-2">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                browse(provider.value)
                                            }
                                        >
                                            Browse
                                        </Button>
                                        {provider.connection_id !== null &&
                                            config.can_connect && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    aria-label={`Disconnect ${provider.label}`}
                                                    onClick={() =>
                                                        disconnect(
                                                            provider.connection_id as number,
                                                        )
                                                    }
                                                >
                                                    <Unplug className="size-4" />
                                                </Button>
                                            )}
                                    </div>
                                ) : config.can_connect ? (
                                    <Button size="sm" asChild>
                                        <a href={provider.connect_url}>
                                            Connect
                                        </a>
                                    </Button>
                                ) : (
                                    <span className="text-muted-foreground text-xs">
                                        Ask an admin to connect
                                    </span>
                                )}
                            </div>

                            {active === provider.value && (
                                <div className="mt-4">
                                    {loading ? (
                                        <p className="text-muted-foreground flex items-center gap-2 text-sm">
                                            <Loader2 className="size-4 animate-spin" />
                                            Loading files…
                                        </p>
                                    ) : needsReconnect ? (
                                        <div className="border-warning/40 bg-warning/5 flex items-center justify-between gap-3 rounded-lg border p-3 text-sm">
                                            <span className="flex items-center gap-2">
                                                <RefreshCw className="size-4" />
                                                This connection needs
                                                reconnecting.
                                            </span>
                                            {config.can_connect && (
                                                <Button size="sm" asChild>
                                                    <a
                                                        href={
                                                            provider.connect_url
                                                        }
                                                    >
                                                        Reconnect
                                                    </a>
                                                </Button>
                                            )}
                                        </div>
                                    ) : files.length === 0 ? (
                                        <p className="text-muted-foreground text-sm">
                                            No importable files found.
                                        </p>
                                    ) : (
                                        <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                            {files.map((file) => (
                                                <li
                                                    key={file.id}
                                                    className="bg-muted/30 flex flex-col gap-2 rounded-lg border p-2"
                                                >
                                                    <div className="bg-muted flex aspect-square items-center justify-center overflow-hidden rounded-md">
                                                        {file.thumbnail_url ? (
                                                            <img
                                                                src={
                                                                    file.thumbnail_url
                                                                }
                                                                alt=""
                                                                className="size-full object-cover"
                                                            />
                                                        ) : (
                                                            <FileImage className="text-muted-foreground size-6" />
                                                        )}
                                                    </div>
                                                    <span
                                                        className="truncate text-xs"
                                                        title={file.name}
                                                    >
                                                        {file.name}
                                                    </span>
                                                    <Button
                                                        size="sm"
                                                        className="w-full"
                                                        disabled={
                                                            importingId ===
                                                            file.id
                                                        }
                                                        onClick={() =>
                                                            runImport(
                                                                provider.value,
                                                                file,
                                                            )
                                                        }
                                                    >
                                                        {importingId === file.id
                                                            ? 'Importing…'
                                                            : 'Import'}
                                                    </Button>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}
