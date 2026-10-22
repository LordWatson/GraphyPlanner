import { Head, useForm } from '@inertiajs/react';
import { Sparkles, Target } from 'lucide-react';
import { Page, PageHeader, Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import {
    edit as brandEdit,
    update as brandUpdate,
} from '@/routes/business/brand';
import { update as profileUpdate } from '@/routes/business/brand/profile';

const DAYS: { value: number; label: string }[] = [
    { value: 1, label: 'Mon' },
    { value: 2, label: 'Tue' },
    { value: 3, label: 'Wed' },
    { value: 4, label: 'Thu' },
    { value: 5, label: 'Fri' },
    { value: 6, label: 'Sat' },
    { value: 7, label: 'Sun' },
];

type Brand = {
    id: number;
    name: string;
    one_liner: string | null;
    location: string | null;
    website: string | null;
    posting_goal: { posts_per_week: number | null; preferred_days: number[] };
};

type BrandBrain = {
    voice: { tone?: string; personality?: string } | null;
    audience: { description?: string } | null;
    offer: { value_proposition?: string } | null;
    content_pillars: string[] | null;
};

export default function BusinessBrand({
    brand,
    brandBrain,
    can,
}: {
    brand: Brand;
    brandBrain: BrandBrain;
    can: { update: boolean };
}) {
    return (
        <Page width="narrow">
            <Head title="Brand" />

            <PageHeader
                eyebrow="Who you are"
                title={
                    <>
                        Your <em>brand</em>.
                    </>
                }
                description="The basics, your posting rhythm, and the voice we write in."
            />

            <BasicsForm brand={brand} disabled={!can.update} />
            <VoiceForm brandBrain={brandBrain} disabled={!can.update} />
        </Page>
    );
}

function BasicsForm({ brand, disabled }: { brand: Brand; disabled: boolean }) {
    const form = useForm({
        name: brand.name,
        one_liner: brand.one_liner ?? '',
        location: brand.location ?? '',
        website: brand.website ?? '',
        posting_goal: {
            posts_per_week: brand.posting_goal.posts_per_week ?? '',
            preferred_days: brand.posting_goal.preferred_days ?? [],
        },
    });

    const toggleDay = (day: number) => {
        const days = form.data.posting_goal.preferred_days;
        form.setData('posting_goal', {
            ...form.data.posting_goal,
            preferred_days: days.includes(day)
                ? days.filter((value) => value !== day)
                : [...days, day].sort((a, b) => a - b),
        });
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.put(profileUpdate().url, { preserveScroll: true });
    };

    return (
        <Panel icon={Target} eyebrow="The basics" title="Brand & posting goal">
            <form onSubmit={submit} className="flex flex-col gap-5">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="name">Brand name</Label>
                    <Input
                        id="name"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        disabled={disabled}
                    />
                    <InputError message={form.errors.name} />
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="one_liner">What you do</Label>
                    <Input
                        id="one_liner"
                        value={form.data.one_liner}
                        onChange={(event) =>
                            form.setData('one_liner', event.target.value)
                        }
                        placeholder="A neighbourhood sourdough bakery"
                        disabled={disabled}
                    />
                    <InputError message={form.errors.one_liner} />
                </div>

                <div className="grid gap-5 sm:grid-cols-2">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="location">Location</Label>
                        <Input
                            id="location"
                            value={form.data.location}
                            onChange={(event) =>
                                form.setData('location', event.target.value)
                            }
                            placeholder="Bristol, UK"
                            disabled={disabled}
                        />
                        <InputError message={form.errors.location} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="website">Website or main handle</Label>
                        <Input
                            id="website"
                            value={form.data.website}
                            onChange={(event) =>
                                form.setData('website', event.target.value)
                            }
                            placeholder="yourbakery.com"
                            disabled={disabled}
                        />
                        <InputError message={form.errors.website} />
                    </div>
                </div>

                <div className="border-border/70 flex flex-col gap-4 border-t pt-5">
                    <div className="flex flex-col gap-1.5 sm:max-w-[200px]">
                        <Label htmlFor="posts_per_week">Posts a week</Label>
                        <Input
                            id="posts_per_week"
                            type="number"
                            min={0}
                            max={21}
                            value={form.data.posting_goal.posts_per_week}
                            onChange={(event) =>
                                form.setData('posting_goal', {
                                    ...form.data.posting_goal,
                                    posts_per_week: event.target.value,
                                })
                            }
                            disabled={disabled}
                        />
                        <InputError
                            message={
                                form.errors[
                                    'posting_goal.posts_per_week' as keyof typeof form.errors
                                ]
                            }
                        />
                    </div>
                    <div className="flex flex-col gap-2">
                        <Label>Preferred days</Label>
                        <div className="flex flex-wrap gap-1.5">
                            {DAYS.map((day) => {
                                const active =
                                    form.data.posting_goal.preferred_days.includes(
                                        day.value,
                                    );

                                return (
                                    <button
                                        key={day.value}
                                        type="button"
                                        disabled={disabled}
                                        aria-pressed={active}
                                        onClick={() => toggleDay(day.value)}
                                        className={cn(
                                            'rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors',
                                            active
                                                ? 'bg-primary text-primary-foreground border-transparent'
                                                : 'border-input hover:bg-muted/50',
                                        )}
                                    >
                                        {day.label}
                                    </button>
                                );
                            })}
                        </div>
                        <p className="text-muted-foreground text-xs">
                            We'll flag these days on Today when you've left them
                            open.
                        </p>
                    </div>
                </div>

                {!disabled && (
                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            variant="brand"
                            disabled={form.processing}
                        >
                            Save brand
                        </Button>
                    </div>
                )}
            </form>
        </Panel>
    );
}

function VoiceForm({
    brandBrain,
    disabled,
}: {
    brandBrain: BrandBrain;
    disabled: boolean;
}) {
    const form = useForm({
        voice: {
            tone: brandBrain.voice?.tone ?? '',
            personality: brandBrain.voice?.personality ?? '',
        },
        audience: {
            description: brandBrain.audience?.description ?? '',
        },
        offer: {
            value_proposition: brandBrain.offer?.value_proposition ?? '',
        },
        content_pillars: (brandBrain.content_pillars ?? []).join('\n'),
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.put(brandUpdate().url, { preserveScroll: true });
    };

    return (
        <Panel icon={Sparkles} eyebrow="How you sound" title="Brand voice">
            <form onSubmit={submit} className="flex flex-col gap-5">
                <div className="grid gap-5 sm:grid-cols-2">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="tone">Tone</Label>
                        <Input
                            id="tone"
                            value={form.data.voice.tone}
                            onChange={(event) =>
                                form.setData('voice', {
                                    ...form.data.voice,
                                    tone: event.target.value,
                                })
                            }
                            placeholder="Warm, playful, down-to-earth"
                            disabled={disabled}
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="personality">Personality</Label>
                        <Input
                            id="personality"
                            value={form.data.voice.personality}
                            onChange={(event) =>
                                form.setData('voice', {
                                    ...form.data.voice,
                                    personality: event.target.value,
                                })
                            }
                            placeholder="The friendly baker next door"
                            disabled={disabled}
                        />
                    </div>
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="audience">Who you're talking to</Label>
                    <Textarea
                        id="audience"
                        value={form.data.audience.description}
                        onChange={(event) =>
                            form.setData('audience', {
                                description: event.target.value,
                            })
                        }
                        placeholder="Locals who love real bread and a good morning coffee."
                        rows={3}
                        disabled={disabled}
                    />
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="offer">What you offer</Label>
                    <Textarea
                        id="offer"
                        value={form.data.offer.value_proposition}
                        onChange={(event) =>
                            form.setData('offer', {
                                value_proposition: event.target.value,
                            })
                        }
                        placeholder="Slow-fermented sourdough, baked fresh every morning."
                        rows={3}
                        disabled={disabled}
                    />
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="content_pillars">
                        Content pillars (one per line)
                    </Label>
                    <Textarea
                        id="content_pillars"
                        value={form.data.content_pillars}
                        onChange={(event) =>
                            form.setData('content_pillars', event.target.value)
                        }
                        placeholder={
                            'Behind the counter\nNew bakes\nLocal events'
                        }
                        rows={4}
                        disabled={disabled}
                    />
                </div>

                {!disabled && (
                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            variant="brand"
                            disabled={form.processing}
                        >
                            Save voice
                        </Button>
                    </div>
                )}
            </form>
        </Panel>
    );
}

BusinessBrand.layout = {
    breadcrumbs: [{ title: 'Brand', href: brandEdit() }],
};
