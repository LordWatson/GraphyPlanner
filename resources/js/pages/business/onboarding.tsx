import { Head, router } from '@inertiajs/react';
import { Check, Loader2, Sparkles } from 'lucide-react';
import { useMemo, useState } from 'react';
import OnboardingController from '@/actions/App/Http/Controllers/Business/OnboardingController';
import { BrandMark } from '@/components/brand-mark';
import { Eyebrow } from '@/components/page';
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
import { cn } from '@/lib/utils';
import { store as storePost } from '@/routes/clients/posts';
import { store as storeSocialAccount } from '@/routes/clients/social-accounts';
import { connect as connectSocialAccount } from '@/routes/social-accounts';

type Voice = {
    voice: {
        tone: string | null;
        personality: string | null;
        do_nots: string[];
    };
    audience: {
        description: string | null;
        demographics: string | null;
        pain_points: string[];
    };
    offer: {
        value_proposition: string | null;
        key_products: string[];
        pricing_notes: string | null;
    };
    content_pillars: string[];
};

type ProgressItem = {
    key: string;
    label: string;
    description: string;
    done: boolean;
    optional: boolean;
};

type Props = {
    brandId: number | null;
    brand: {
        name: string;
        one_liner: string | null;
        location: string | null;
        website: string | null;
        brand_kind: string | null;
    };
    voice: Voice;
    progress: {
        items: ProgressItem[];
        requiredComplete: boolean;
        completedAt: string | null;
    };
    platforms: { value: string; label: string }[];
    accounts: {
        id: number;
        platform: string;
        handle: string;
        connected: boolean;
    }[];
    defaultTimezone: string;
    aiEnabled: boolean;
};

const STEPS = [
    { key: 'brand', title: 'Your brand', number: '01' },
    { key: 'voice', title: 'Your voice', number: '02' },
    { key: 'accounts', title: 'Connect accounts', number: '03' },
    { key: 'first_post', title: 'Your first post', number: '04' },
] as const;

/** Textarea helpers: one item per line. */
const toLines = (items: string[]) => items.join('\n');
const fromLines = (value: string): string[] =>
    value
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '');

export default function BusinessOnboarding({
    brandId,
    brand,
    voice,
    progress,
    platforms,
    accounts,
    defaultTimezone,
    aiEnabled,
}: Props) {
    const [step, setStep] = useState(0);

    return (
        <div className="bg-background text-foreground min-h-svh">
            <Head title="Set up your brand" />

            <header className="border-border/70 bg-background/80 sticky top-0 z-10 flex items-center justify-between border-b px-5 py-4 backdrop-blur sm:px-8">
                <BrandMark size="sm" wordmark />
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => router.post(OnboardingController.skip.url())}
                >
                    Skip for now
                </Button>
            </header>

            <main className="mx-auto flex w-full max-w-2xl flex-col gap-8 px-5 py-8 sm:px-8 sm:py-12">
                <div className="animate-rise flex flex-col gap-2">
                    <Eyebrow dash>Welcome to Alima</Eyebrow>
                    <h1 className="font-display text-[2rem] leading-[1.05] text-balance sm:text-[2.5rem]">
                        Let&rsquo;s get {brand.name || 'your brand'} ready.
                    </h1>
                    <p className="text-muted-foreground text-[15px] text-pretty">
                        Four quick steps. You can skip any of them and come back
                        later.
                    </p>
                </div>

                <Stepper
                    step={step}
                    progress={progress.items}
                    onSelect={setStep}
                />

                <div className="animate-rise [animation-delay:80ms]">
                    {step === 0 && (
                        <BrandStep brand={brand} onNext={() => setStep(1)} />
                    )}
                    {step === 1 && (
                        <VoiceStep
                            brand={brand}
                            voice={voice}
                            aiEnabled={aiEnabled}
                            onBack={() => setStep(0)}
                            onNext={() => setStep(2)}
                        />
                    )}
                    {step === 2 && (
                        <AccountsStep
                            brandId={brandId}
                            platforms={platforms}
                            accounts={accounts}
                            defaultTimezone={defaultTimezone}
                            onBack={() => setStep(1)}
                            onNext={() => setStep(3)}
                        />
                    )}
                    {step === 3 && (
                        <FirstPostStep
                            brandId={brandId}
                            onBack={() => setStep(2)}
                        />
                    )}
                </div>
            </main>
        </div>
    );
}

function Stepper({
    step,
    progress,
    onSelect,
}: {
    step: number;
    progress: ProgressItem[];
    onSelect: (index: number) => void;
}) {
    const doneByKey = useMemo(
        () => Object.fromEntries(progress.map((item) => [item.key, item.done])),
        [progress],
    );

    return (
        <ol className="flex flex-wrap gap-2">
            {STEPS.map((definition, index) => {
                const done = doneByKey[definition.key] ?? false;
                const active = index === step;

                return (
                    <li key={definition.key}>
                        <button
                            type="button"
                            onClick={() => onSelect(index)}
                            aria-current={active ? 'step' : undefined}
                            className={cn(
                                'flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors',
                                active
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : done
                                      ? 'border-success/40 bg-success/10 text-success'
                                      : 'border-input text-muted-foreground hover:border-foreground/40',
                            )}
                        >
                            {done ? (
                                <Check className="size-3.5" />
                            ) : (
                                <span className="font-mono tabular-nums">
                                    {definition.number}
                                </span>
                            )}
                            {definition.title}
                        </button>
                    </li>
                );
            })}
        </ol>
    );
}

function StepShell({
    title,
    description,
    children,
    footer,
}: {
    title: string;
    description: string;
    children: React.ReactNode;
    footer: React.ReactNode;
}) {
    return (
        <div className="bg-card border-border/70 flex flex-col gap-6 rounded-2xl border p-6 shadow-sm sm:p-8">
            <div className="flex flex-col gap-1.5">
                <h2 className="font-display text-xl">{title}</h2>
                <p className="text-muted-foreground text-sm text-pretty">
                    {description}
                </p>
            </div>
            {children}
            <div className="flex items-center justify-between gap-3 pt-2">
                {footer}
            </div>
        </div>
    );
}

function BrandStep({
    brand,
    onNext,
}: {
    brand: Props['brand'];
    onNext: () => void;
}) {
    const [form, setForm] = useState({
        name: brand.name ?? '',
        one_liner: brand.one_liner ?? '',
        location: brand.location ?? '',
        website: brand.website ?? '',
    });
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const save = () => {
        setSaving(true);
        router.put(OnboardingController.updateBrand.url(), form, {
            preserveScroll: true,
            onError: (e) => setErrors(e as Record<string, string>),
            onSuccess: () => {
                setErrors({});
                onNext();
            },
            onFinish: () => setSaving(false),
        });
    };

    return (
        <StepShell
            title="Your brand"
            description="So captions and ideas sound like you — not like a robot."
            footer={
                <>
                    <span className="text-muted-foreground text-xs">
                        Step 1 of 4
                    </span>
                    <Button onClick={save} disabled={saving}>
                        {saving && <Loader2 className="size-4 animate-spin" />}
                        Save &amp; continue
                    </Button>
                </>
            }
        >
            <div className="grid gap-5">
                <Field label="Brand name" error={errors.name}>
                    <Input
                        value={form.name}
                        onChange={(e) =>
                            setForm({ ...form, name: e.target.value })
                        }
                        placeholder="e.g. Sourdough & Co."
                    />
                </Field>
                <Field label="What do you do?" error={errors.one_liner}>
                    <Input
                        value={form.one_liner}
                        onChange={(e) =>
                            setForm({ ...form, one_liner: e.target.value })
                        }
                        placeholder="One line — e.g. A neighbourhood bakery doing slow sourdough."
                    />
                </Field>
                <Field
                    label="Where are you?"
                    hint="City and country — we use it for local and seasonal ideas."
                    error={errors.location}
                >
                    <Input
                        value={form.location}
                        onChange={(e) =>
                            setForm({ ...form, location: e.target.value })
                        }
                        placeholder="e.g. Bristol, UK"
                    />
                </Field>
                <Field
                    label="Website or main handle"
                    hint="Optional — we can read it to draft your voice next."
                    error={errors.website}
                >
                    <Input
                        value={form.website}
                        onChange={(e) =>
                            setForm({ ...form, website: e.target.value })
                        }
                        placeholder="yourbrand.com or @yourbrand"
                    />
                </Field>
            </div>
        </StepShell>
    );
}

function VoiceStep({
    brand,
    voice,
    aiEnabled,
    onBack,
    onNext,
}: {
    brand: Props['brand'];
    voice: Voice;
    aiEnabled: boolean;
    onBack: () => void;
    onNext: () => void;
}) {
    const [state, setState] = useState({
        tone: voice.voice.tone ?? '',
        personality: voice.voice.personality ?? '',
        do_nots: toLines(voice.voice.do_nots ?? []),
        audience_description: voice.audience.description ?? '',
        pain_points: toLines(voice.audience.pain_points ?? []),
        value_proposition: voice.offer.value_proposition ?? '',
        content_pillars: toLines(voice.content_pillars ?? []),
    });
    const [drafting, setDrafting] = useState(false);
    const [saving, setSaving] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);

    const draftWithAi = async () => {
        setDrafting(true);
        setNotice(null);

        const result = await http.post<{
            draft: Voice & { succeeded: boolean };
        }>(
            OnboardingController.draftProfile.url(),
            {
                name: brand.name,
                one_liner: brand.one_liner,
                location: brand.location,
                website: brand.website,
            },
            {
                toastOnError: true,
                rateLimitMessage:
                    'You’ve used AI a lot just now — give it a minute and try again.',
            },
        );

        setDrafting(false);

        if (!result.ok) {
            return;
        }

        const draft = result.data.draft;

        if (!draft.succeeded) {
            setNotice(
                'We couldn’t draft this one — no problem, fill it in yourself below and tweak any time.',
            );
            return;
        }

        setState({
            tone: draft.voice.tone ?? '',
            personality: draft.voice.personality ?? '',
            do_nots: toLines(draft.voice.do_nots ?? []),
            audience_description: draft.audience.description ?? '',
            pain_points: toLines(draft.audience.pain_points ?? []),
            value_proposition: draft.offer.value_proposition ?? '',
            content_pillars: toLines(draft.content_pillars ?? []),
        });
        setNotice('Draft ready — have a read and make it yours.');
    };

    const save = () => {
        setSaving(true);
        router.put(
            OnboardingController.saveVoice.url(),
            {
                voice: {
                    tone: state.tone,
                    personality: state.personality,
                    do_nots: fromLines(state.do_nots),
                },
                audience: {
                    description: state.audience_description,
                    pain_points: fromLines(state.pain_points),
                },
                offer: {
                    value_proposition: state.value_proposition,
                },
                content_pillars: fromLines(state.content_pillars),
            },
            {
                preserveScroll: true,
                onSuccess: onNext,
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <StepShell
            title="Your voice"
            description="Let AI draft a starting point from what you told us, then make it yours."
            footer={
                <>
                    <Button variant="ghost" onClick={onBack}>
                        Back
                    </Button>
                    <Button onClick={save} disabled={saving}>
                        {saving && <Loader2 className="size-4 animate-spin" />}
                        Save &amp; continue
                    </Button>
                </>
            }
        >
            <div className="flex flex-col gap-5">
                {aiEnabled && (
                    <div className="border-primary/30 bg-primary/5 flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-pretty">
                            Want a head start? We&rsquo;ll draft your voice from
                            your brand details.
                        </p>
                        <Button
                            variant="secondary"
                            onClick={draftWithAi}
                            disabled={drafting}
                            className="shrink-0"
                        >
                            {drafting ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : (
                                <Sparkles className="size-4" />
                            )}
                            Draft with AI
                        </Button>
                    </div>
                )}

                {notice && (
                    <p className="text-muted-foreground text-sm text-pretty">
                        {notice}
                    </p>
                )}

                <Field label="Tone">
                    <Input
                        value={state.tone}
                        onChange={(e) =>
                            setState({ ...state, tone: e.target.value })
                        }
                        placeholder="e.g. Warm, playful, down-to-earth"
                    />
                </Field>
                <Field label="Personality">
                    <Input
                        value={state.personality}
                        onChange={(e) =>
                            setState({ ...state, personality: e.target.value })
                        }
                        placeholder="e.g. The friendly baker who knows your order"
                    />
                </Field>
                <Field
                    label="Who you talk to"
                    hint="A sentence about your audience."
                >
                    <Textarea
                        value={state.audience_description}
                        onChange={(e) =>
                            setState({
                                ...state,
                                audience_description: e.target.value,
                            })
                        }
                        rows={2}
                        placeholder="e.g. Locals who care about real food and a friendly morning."
                    />
                </Field>
                <Field
                    label="Content pillars"
                    hint="The 3–5 themes you post about — one per line."
                >
                    <Textarea
                        value={state.content_pillars}
                        onChange={(e) =>
                            setState({
                                ...state,
                                content_pillars: e.target.value,
                            })
                        }
                        rows={4}
                        placeholder={
                            'Behind the counter\nToday’s bake\nMeet the team'
                        }
                    />
                </Field>
                <Field
                    label="Things to avoid"
                    hint="Words or topics to stay away from — one per line."
                >
                    <Textarea
                        value={state.do_nots}
                        onChange={(e) =>
                            setState({ ...state, do_nots: e.target.value })
                        }
                        rows={2}
                        placeholder={'Hard selling\nCorporate jargon'}
                    />
                </Field>
            </div>
        </StepShell>
    );
}

function AccountsStep({
    brandId,
    platforms,
    accounts,
    defaultTimezone,
    onBack,
    onNext,
}: {
    brandId: number | null;
    platforms: { value: string; label: string }[];
    accounts: Props['accounts'];
    defaultTimezone: string;
    onBack: () => void;
    onNext: () => void;
}) {
    const [platform, setPlatform] = useState(platforms[0]?.value ?? '');
    const [handle, setHandle] = useState('');
    const [adding, setAdding] = useState(false);
    const [connectingId, setConnectingId] = useState<number | null>(null);

    const addAccount = () => {
        if (brandId === null || platform === '' || handle.trim() === '') {
            return;
        }

        setAdding(true);
        router.post(
            storeSocialAccount.url(brandId),
            { platform, handle: handle.trim(), timezone: defaultTimezone },
            {
                preserveScroll: true,
                onSuccess: () => setHandle(''),
                onFinish: () => setAdding(false),
            },
        );
    };

    const connect = async (id: number) => {
        setConnectingId(id);
        const result = await http.post<{ url: string }>(
            connectSocialAccount.url(id),
            {},
            { toastOnError: true },
        );
        setConnectingId(null);

        if (result.ok && result.data.url) {
            window.open(result.data.url, '_blank', 'noopener');
        }
    };

    return (
        <StepShell
            title="Connect accounts"
            description="Add the platforms you post to. You can connect more any time."
            footer={
                <>
                    <Button variant="ghost" onClick={onBack}>
                        Back
                    </Button>
                    <Button onClick={onNext}>
                        {accounts.some((account) => account.connected)
                            ? 'Continue'
                            : 'I’ll do this later'}
                    </Button>
                </>
            }
        >
            <div className="flex flex-col gap-5">
                {accounts.length > 0 && (
                    <ul className="divide-border/70 border-border/70 divide-y rounded-xl border">
                        {accounts.map((account) => (
                            <li
                                key={account.id}
                                className="flex items-center justify-between gap-3 px-4 py-3"
                            >
                                <div className="flex flex-col">
                                    <span className="text-sm font-medium capitalize">
                                        {account.platform}
                                    </span>
                                    <span className="text-muted-foreground text-xs">
                                        {account.handle}
                                    </span>
                                </div>
                                {account.connected ? (
                                    <span className="text-success flex items-center gap-1.5 text-xs font-medium">
                                        <Check className="size-4" /> Connected
                                    </span>
                                ) : (
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        onClick={() => connect(account.id)}
                                        disabled={connectingId === account.id}
                                    >
                                        {connectingId === account.id && (
                                            <Loader2 className="size-4 animate-spin" />
                                        )}
                                        Connect
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                <div className="border-border/70 flex flex-col gap-3 rounded-xl border border-dashed p-4">
                    <span className="text-sm font-medium">Add an account</span>
                    <div className="flex flex-col gap-3 sm:flex-row">
                        <Select value={platform} onValueChange={setPlatform}>
                            <SelectTrigger className="sm:w-44">
                                <SelectValue placeholder="Platform" />
                            </SelectTrigger>
                            <SelectContent>
                                {platforms.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Input
                            value={handle}
                            onChange={(e) => setHandle(e.target.value)}
                            placeholder="@yourbrand"
                            className="flex-1"
                        />
                        <Button
                            variant="outline"
                            onClick={addAccount}
                            disabled={adding || handle.trim() === ''}
                        >
                            {adding && (
                                <Loader2 className="size-4 animate-spin" />
                            )}
                            Add
                        </Button>
                    </div>
                </div>
            </div>
        </StepShell>
    );
}

function FirstPostStep({
    brandId,
    onBack,
}: {
    brandId: number | null;
    onBack: () => void;
}) {
    const [creating, setCreating] = useState(false);

    const createPost = () => {
        if (brandId === null) {
            return;
        }

        setCreating(true);
        router.post(
            storePost.url(brandId),
            {},
            { onFinish: () => setCreating(false) },
        );
    };

    const finish = () => router.post(OnboardingController.complete.url());

    return (
        <StepShell
            title="Your first post"
            description="Open the editor to draft and schedule your first post. We’ll keep everything you’ve set up."
            footer={
                <>
                    <Button variant="ghost" onClick={onBack}>
                        Back
                    </Button>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={finish}>
                            Finish setup
                        </Button>
                        <Button onClick={createPost} disabled={creating}>
                            {creating && (
                                <Loader2 className="size-4 animate-spin" />
                            )}
                            Create your first post
                        </Button>
                    </div>
                </>
            }
        >
            <p className="text-muted-foreground text-sm text-pretty">
                That&rsquo;s the setup done. When you create your first post the
                editor opens with your brand voice ready to go — pick a time and
                you&rsquo;re live.
            </p>
        </StepShell>
    );
}

function Field({
    label,
    hint,
    error,
    children,
}: {
    label: string;
    hint?: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <Label>{label}</Label>
            {children}
            {hint && !error && (
                <p className="text-muted-foreground text-xs">{hint}</p>
            )}
            {error && <p className="text-destructive text-xs">{error}</p>}
        </div>
    );
}
