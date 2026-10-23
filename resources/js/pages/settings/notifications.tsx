import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { edit as editNotifications, update } from '@/routes/notifications';

type PrefRow = {
    category: string;
    label: string;
    description: string;
    in_app: boolean;
    digest: boolean;
    in_app_locked: boolean;
};

/**
 * Step M-6.7 (I-12.1) — per-user notification preferences: for each category, whether it shows in
 * the bell (in-app) and whether it's included in the daily digest email.
 */
export default function NotificationsSettings({
    preferences,
}: {
    preferences: PrefRow[];
}) {
    const [rows, setRows] = useState(preferences);
    const [saving, setSaving] = useState(false);

    const setValue = (
        category: string,
        channel: 'in_app' | 'digest',
        value: boolean,
    ) =>
        setRows((prev) =>
            prev.map((row) =>
                row.category === category ? { ...row, [channel]: value } : row,
            ),
        );

    const save = () => {
        setSaving(true);
        const payload: Record<string, { in_app: boolean; digest: boolean }> =
            {};
        for (const row of rows) {
            payload[row.category] = { in_app: row.in_app, digest: row.digest };
        }
        router.put(
            update().url,
            { preferences: payload },
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success('Notification preferences saved.'),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <>
            <Head title="Notification settings" />
            <h1 className="sr-only">Notification settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Notifications"
                    description="Choose what reaches your notification bell and your daily digest email."
                />

                <div className="border-border overflow-hidden rounded-xl border">
                    <div className="bg-muted/40 text-muted-foreground grid grid-cols-[1fr_5rem_5rem] gap-2 px-4 py-2.5 text-xs font-medium">
                        <span>Category</span>
                        <span className="text-center">In app</span>
                        <span className="text-center">Digest</span>
                    </div>
                    {rows.map((row) => (
                        <div
                            key={row.category}
                            className="border-border grid grid-cols-[1fr_5rem_5rem] items-center gap-2 border-t px-4 py-3"
                        >
                            <div className="flex flex-col gap-0.5">
                                <span className="text-sm font-medium">
                                    {row.label}
                                </span>
                                <span className="text-muted-foreground text-xs">
                                    {row.description}
                                </span>
                            </div>
                            <div className="flex justify-center">
                                <Checkbox
                                    checked={row.in_app}
                                    disabled={row.in_app_locked}
                                    onCheckedChange={(v) =>
                                        setValue(
                                            row.category,
                                            'in_app',
                                            v === true,
                                        )
                                    }
                                    aria-label={`${row.label} in app`}
                                />
                            </div>
                            <div className="flex justify-center">
                                <Checkbox
                                    checked={row.digest}
                                    onCheckedChange={(v) =>
                                        setValue(
                                            row.category,
                                            'digest',
                                            v === true,
                                        )
                                    }
                                    aria-label={`${row.label} in digest`}
                                />
                            </div>
                        </div>
                    ))}
                </div>

                <Button onClick={save} disabled={saving}>
                    Save preferences
                </Button>
            </div>
        </>
    );
}

NotificationsSettings.layout = {
    breadcrumbs: [
        { title: 'Notification settings', href: editNotifications() },
    ],
};
