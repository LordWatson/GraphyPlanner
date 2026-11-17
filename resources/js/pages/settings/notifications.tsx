import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import { PushRemindersCard } from '@/components/pwa/push-reminders-card';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit as editNotifications, update } from '@/routes/notifications';
import whatsappNumber from '@/routes/whatsapp-number';

type Channel = 'in_app' | 'digest' | 'whatsapp';

type PrefRow = {
    category: string;
    label: string;
    description: string;
    in_app: boolean;
    digest: boolean;
    whatsapp: boolean;
    in_app_locked: boolean;
};

type WhatsAppState = {
    masked_phone: string | null;
    verified: boolean;
    awaiting_code: boolean;
};

/**
 * Step M-6.7 (I-12.1) / W-1.1 — per-user notification preferences: for each category, whether it
 * shows in the bell, whether it joins the daily digest email, and whether it pings WhatsApp.
 *
 * The WhatsApp column is disabled until a number is verified, because an opt-in with nowhere to
 * send is a switch that does nothing. Verification lives in the panel below it: a code goes to the
 * number by WhatsApp, which is also what proves the number can receive WhatsApp at all.
 */
export default function NotificationsSettings({
    preferences,
    whatsapp,
}: {
    preferences: PrefRow[];
    whatsapp: WhatsAppState;
}) {
    const [rows, setRows] = useState(preferences);
    const [saving, setSaving] = useState(false);

    const numberForm = useForm({ phone: '' });
    const codeForm = useForm({ code: '' });

    const setValue = (category: string, channel: Channel, value: boolean) =>
        setRows((prev) =>
            prev.map((row) =>
                row.category === category ? { ...row, [channel]: value } : row,
            ),
        );

    const save = () => {
        setSaving(true);
        const payload: Record<
            string,
            { in_app: boolean; digest: boolean; whatsapp: boolean }
        > = {};
        for (const row of rows) {
            payload[row.category] = {
                in_app: row.in_app,
                digest: row.digest,
                whatsapp: row.whatsapp,
            };
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

    const sendCode = () =>
        numberForm.post(whatsappNumber.store().url, {
            preserveScroll: true,
            onSuccess: () => {
                numberForm.reset();
                toast.success('Code sent. Check WhatsApp.');
            },
        });

    const confirmCode = () =>
        codeForm.put(whatsappNumber.update().url, {
            preserveScroll: true,
            onSuccess: () => {
                codeForm.reset();
                toast.success('WhatsApp number verified.');
            },
        });

    const removeNumber = () =>
        router.delete(whatsappNumber.destroy().url, {
            preserveScroll: true,
            onSuccess: () => toast.success('WhatsApp number removed.'),
        });

    return (
        <>
            <Head title="Notification settings" />
            <h1 className="sr-only">Notification settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Notifications"
                    description="Choose what reaches your notification bell, your daily digest email and your phone."
                />

                <div className="border-border overflow-hidden rounded-xl border">
                    <div className="bg-muted/40 text-muted-foreground grid grid-cols-[1fr_4rem_4rem_5rem] gap-2 px-4 py-2.5 text-xs font-medium">
                        <span>Category</span>
                        <span className="text-center">In app</span>
                        <span className="text-center">Digest</span>
                        <span className="text-center">WhatsApp</span>
                    </div>
                    {rows.map((row) => (
                        <div
                            key={row.category}
                            className="border-border grid grid-cols-[1fr_4rem_4rem_5rem] items-center gap-2 border-t px-4 py-3"
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
                            <div className="flex justify-center">
                                <Checkbox
                                    checked={row.whatsapp}
                                    disabled={!whatsapp.verified}
                                    onCheckedChange={(v) =>
                                        setValue(
                                            row.category,
                                            'whatsapp',
                                            v === true,
                                        )
                                    }
                                    aria-label={`${row.label} on WhatsApp`}
                                />
                            </div>
                        </div>
                    ))}
                </div>

                <Button onClick={save} disabled={saving}>
                    Save preferences
                </Button>

                <div className="border-border space-y-4 rounded-xl border p-5">
                    <div className="space-y-1">
                        <h2 className="text-sm font-medium">
                            Your WhatsApp number
                        </h2>
                        <p className="text-muted-foreground text-xs">
                            {whatsapp.verified
                                ? `Verified, ending ${whatsapp.masked_phone}. Reply STOP on WhatsApp at any time to switch everything off.`
                                : 'We send a code to the number to check it is yours. Nothing else is sent until you verify it.'}
                        </p>
                    </div>

                    {whatsapp.verified ? (
                        <Button variant="outline" onClick={removeNumber}>
                            Remove number
                        </Button>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="whatsapp-phone">
                                    Number, with country code
                                </Label>
                                <Input
                                    id="whatsapp-phone"
                                    type="tel"
                                    autoComplete="tel"
                                    placeholder="+44 7700 900123"
                                    value={numberForm.data.phone}
                                    onChange={(e) =>
                                        numberForm.setData(
                                            'phone',
                                            e.target.value,
                                        )
                                    }
                                />
                                {numberForm.errors.phone && (
                                    <p className="text-destructive text-xs">
                                        {numberForm.errors.phone}
                                    </p>
                                )}
                                <Button
                                    onClick={sendCode}
                                    disabled={numberForm.processing}
                                >
                                    Send code
                                </Button>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="whatsapp-code">
                                    Code from WhatsApp
                                </Label>
                                <Input
                                    id="whatsapp-code"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    maxLength={6}
                                    placeholder="123456"
                                    value={codeForm.data.code}
                                    onChange={(e) =>
                                        codeForm.setData('code', e.target.value)
                                    }
                                />
                                {codeForm.errors.code && (
                                    <p className="text-destructive text-xs">
                                        {codeForm.errors.code}
                                    </p>
                                )}
                                <Button
                                    variant="outline"
                                    onClick={confirmCode}
                                    disabled={
                                        codeForm.processing ||
                                        !whatsapp.awaiting_code
                                    }
                                >
                                    Confirm number
                                </Button>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <PushRemindersCard workspace="agency" />
        </>
    );
}

NotificationsSettings.layout = {
    breadcrumbs: [
        { title: 'Notification settings', href: editNotifications() },
    ],
};
