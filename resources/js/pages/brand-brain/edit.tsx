import { Form, Head } from '@inertiajs/react';
import BrandBrainController from '@/actions/App/Http/Controllers/BrandBrainController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { index } from '@/routes/clients';

type BrandBrainData = {
    voice: { tone?: string; personality?: string; do_nots?: string[] } | null;
    audience: { description?: string; demographics?: string; pain_points?: string[] } | null;
    offer: { value_proposition?: string; key_products?: string[]; pricing_notes?: string } | null;
    visual: {
        color_palette?: string[];
        typography?: string;
        imagery_style?: string;
        logo_usage_notes?: string;
    } | null;
    music_policy: { allowed_genres?: string[]; disallowed_genres?: string[]; notes?: string } | null;
    hashtag_policy: { always_use?: string[]; never_use?: string[]; rotation_notes?: string } | null;
    content_pillars: string[] | null;
};

function toLines(items?: string[] | null): string {
    return (items ?? []).join('\n');
}

const textareaClass =
    'border-input dark:bg-input/30 flex min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';

export default function BrandBrainEdit({
    client,
    brandBrain,
    can,
}: {
    client: { id: number; name: string };
    brandBrain: BrandBrainData;
    can: { update: boolean };
}) {
    return (
        <>
            <Head title={`Brand brain — ${client.name}`} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
                <Heading title="Brand brain" description={`Voice, audience, and creative rules for ${client.name}`} />

                <Form {...BrandBrainController.update.form(client.id)} className="space-y-6">
                    {({ processing, errors }) => (
                        <>
                            <Tabs defaultValue="voice">
                                <TabsList>
                                    <TabsTrigger value="voice">Voice</TabsTrigger>
                                    <TabsTrigger value="audience">Audience</TabsTrigger>
                                    <TabsTrigger value="offer">Offer</TabsTrigger>
                                    <TabsTrigger value="visual">Visual</TabsTrigger>
                                    <TabsTrigger value="music">Music policy</TabsTrigger>
                                    <TabsTrigger value="hashtags">Hashtag policy</TabsTrigger>
                                    <TabsTrigger value="pillars">Content pillars</TabsTrigger>
                                </TabsList>

                                <TabsContent value="voice" className="space-y-4 pt-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="voice_tone">Tone</Label>
                                        <Input
                                            id="voice_tone"
                                            name="voice[tone]"
                                            defaultValue={brandBrain.voice?.tone ?? ''}
                                        />
                                        <InputError message={errors['voice.tone']} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="voice_personality">Personality</Label>
                                        <Input
                                            id="voice_personality"
                                            name="voice[personality]"
                                            defaultValue={brandBrain.voice?.personality ?? ''}
                                        />
                                        <InputError message={errors['voice.personality']} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="voice_do_nots">Do not (one per line)</Label>
                                        <textarea
                                            id="voice_do_nots"
                                            name="voice[do_nots]"
                                            rows={4}
                                            className={textareaClass}
                                            defaultValue={toLines(brandBrain.voice?.do_nots)}
                                        />
                                        <InputError message={errors['voice.do_nots']} />
                                    </div>
                                </TabsContent>

                                <TabsContent value="audience" className="space-y-4 pt-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="audience_description">Description</Label>
                                        <textarea
                                            id="audience_description"
                                            name="audience[description]"
                                            rows={3}
                                            className={textareaClass}
                                            defaultValue={brandBrain.audience?.description ?? ''}
                                        />
                                        <InputError message={errors['audience.description']} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="audience_demographics">Demographics</Label>
                                        <Input
                                            id="audience_demographics"
                                            name="audience[demographics]"
                                            defaultValue={brandBrain.audience?.demographics ?? ''}
                                        />
                                        <InputError message={errors['audience.demographics']} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="audience_pain_points">Pain points (one per line)</Label>
                                        <textarea
                                            id="audience_pain_points"
                                            name="audience[pain_points]"
                                            rows={4}
                                            className={textareaClass}
                                            defaultValue={toLines(brandBrain.audience?.pain_points)}
                                        />
                                        <InputError message={errors['audience.pain_points']} />
                                    </div>
                                </TabsContent>

                                <TabsContent value="offer" className="space-y-4 pt-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="offer_value_proposition">Value proposition</Label>
                                        <textarea
                                            id="offer_value_proposition"
                                            name="offer[value_proposition]"
                                            rows={3}
                                            className={textareaClass}
                                            defaultValue={brandBrain.offer?.value_proposition ?? ''}
                                        />
                                        <InputError message={errors['offer.value_proposition']} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="offer_key_products">Key products/services (one per line)</Label>
                                        <textarea
                                            id="offer_key_products"
                                            name="offer[key_products]"
                                            rows={4}
                                            className={textareaClass}
                                            defaultValue={toLines(brandBrain.offer?.key_products)}
                                        />
                                        <InputError message={errors['offer.key_products']} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="offer_pricing_notes">Pricing notes</Label>
                                        <textarea
                                            id="offer_pricing_notes"
                                            name="offer[pricing_notes]"
                                            rows={3}
                                            className={textareaClass}
                                            defaultValue={brandBrain.offer?.pricing_notes ?? ''}
                                        />
                                        <InputError message={errors['offer.pricing_notes']} />
                                    </div>
                                </TabsContent>

                                <TabsContent value="visual" className="space-y-4 pt-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="visual_color_palette">Color palette (one per line)</Label>
                                        <textarea
                                            id="visual_color_palette"
                                            name="visual[color_palette]"
                                            rows={3}
                                            className={textareaClass}
                                            defaultValue={toLines(brandBrain.visual?.color_palette)}
                                        />
                                        <InputError message={errors['visual.color_palette']} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="visual_typography">Typography</Label>
                                        <Input
                                            id="visual_typography"
                                            name="visual[typography]"
                                            defaultValue={brandBrain.visual?.typography ?? ''}
                                        />
                                        <InputError message={errors['visual.typography']} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="visual_imagery_style">Imagery style</Label>
                                        <textarea
                                            id="visual_imagery_style"
                                            name="visual[imagery_style]"
                                            rows={3}
                                            className={textareaClass}
                                            defaultValue={brandBrain.visual?.imagery_style ?? ''}
                                        />
                                        <InputError message={errors['visual.imagery_style']} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="visual_logo_usage_notes">Logo usage notes</Label>
                                        <textarea
                                            id="visual_logo_usage_notes"
                                            name="visual[logo_usage_notes]"
                                            rows={3}
                                            className={textareaClass}
                                            defaultValue={brandBrain.visual?.logo_usage_notes ?? ''}
                                        />
                                        <InputError message={errors['visual.logo_usage_notes']} />
                                    </div>
                                </TabsContent>

                                <TabsContent value="music" className="space-y-4 pt-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="music_allowed_genres">Allowed genres (one per line)</Label>
                                        <textarea
                                            id="music_allowed_genres"
                                            name="music_policy[allowed_genres]"
                                            rows={3}
                                            className={textareaClass}
                                            defaultValue={toLines(brandBrain.music_policy?.allowed_genres)}
                                        />
                                        <InputError message={errors['music_policy.allowed_genres']} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="music_disallowed_genres">
                                            Disallowed genres (one per line)
                                        </Label>
                                        <textarea
                                            id="music_disallowed_genres"
                                            name="music_policy[disallowed_genres]"
                                            rows={3}
                                            className={textareaClass}
                                            defaultValue={toLines(brandBrain.music_policy?.disallowed_genres)}
                                        />
                                        <InputError message={errors['music_policy.disallowed_genres']} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="music_notes">Notes</Label>
                                        <textarea
                                            id="music_notes"
                                            name="music_policy[notes]"
                                            rows={3}
                                            className={textareaClass}
                                            defaultValue={brandBrain.music_policy?.notes ?? ''}
                                        />
                                        <InputError message={errors['music_policy.notes']} />
                                    </div>
                                </TabsContent>

                                <TabsContent value="hashtags" className="space-y-4 pt-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="hashtags_always_use">Always use (one per line)</Label>
                                        <textarea
                                            id="hashtags_always_use"
                                            name="hashtag_policy[always_use]"
                                            rows={3}
                                            className={textareaClass}
                                            defaultValue={toLines(brandBrain.hashtag_policy?.always_use)}
                                        />
                                        <InputError message={errors['hashtag_policy.always_use']} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="hashtags_never_use">Never use (one per line)</Label>
                                        <textarea
                                            id="hashtags_never_use"
                                            name="hashtag_policy[never_use]"
                                            rows={3}
                                            className={textareaClass}
                                            defaultValue={toLines(brandBrain.hashtag_policy?.never_use)}
                                        />
                                        <InputError message={errors['hashtag_policy.never_use']} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="hashtags_rotation_notes">Rotation notes</Label>
                                        <textarea
                                            id="hashtags_rotation_notes"
                                            name="hashtag_policy[rotation_notes]"
                                            rows={3}
                                            className={textareaClass}
                                            defaultValue={brandBrain.hashtag_policy?.rotation_notes ?? ''}
                                        />
                                        <InputError message={errors['hashtag_policy.rotation_notes']} />
                                    </div>
                                </TabsContent>

                                <TabsContent value="pillars" className="space-y-4 pt-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="content_pillars">Content pillars (one per line)</Label>
                                        <textarea
                                            id="content_pillars"
                                            name="content_pillars"
                                            rows={6}
                                            className={textareaClass}
                                            defaultValue={toLines(brandBrain.content_pillars)}
                                        />
                                        <InputError message={errors.content_pillars} />
                                    </div>
                                </TabsContent>
                            </Tabs>

                            {can.update && (
                                <div className="flex items-center gap-4">
                                    <Button disabled={processing}>Save brand brain</Button>
                                </div>
                            )}
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

BrandBrainEdit.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }, { title: 'Brand brain', href: index() }],
};
