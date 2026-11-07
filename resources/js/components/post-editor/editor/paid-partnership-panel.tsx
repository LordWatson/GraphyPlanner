import { Handshake } from 'lucide-react';
import InputError from '@/components/input-error';
import { Panel } from '@/components/page';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

/** The disclosure words the readiness check accepts — kept in step with `PartnershipDisclosure`. */
const PARTNERSHIP_WORDS = '#ad, #sponsored, #paidpartnership or #gifted';

const NO_DEAL = '__none';

/**
 * Step CG-5.1 (B-11.1) / CG-5.2 — declaring a paid partnership. Switching it on makes the
 * readiness check a **blocker** until every channel's caption discloses it, which is why the hint
 * names the words that count rather than leaving the owner to guess.
 */
export function PaidPartnershipPanel({
    errors,
}: {
    errors: Record<string, string>;
}) {
    const { editor, page } = usePostEditorContext();
    const { state } = editor;

    return (
        <Panel
            eyebrow="06"
            title="Paid partnership"
            description="Tell people when a post is paid for, gifted or affiliate — it's the law, and we won't let it go out without it."
            icon={Handshake}
            className="mt-5"
            bodyClassName="grid gap-5"
        >
            <label className="flex items-start gap-3 text-sm">
                <Checkbox
                    checked={state.isPaidPartnership}
                    onCheckedChange={(checked) =>
                        editor.update({ isPaidPartnership: checked === true })
                    }
                    className="mt-0.5"
                />
                <span>
                    This post is a paid partnership, gifted or affiliate.
                    <span className="text-muted-foreground block">
                        Your caption will need {PARTNERSHIP_WORDS} on every
                        channel before it can be scheduled.
                    </span>
                </span>
            </label>

            {state.isPaidPartnership && (
                <div className="grid gap-5 sm:grid-cols-3">
                    <div className="grid content-start gap-2">
                        <Label htmlFor="partner_name">Partner</Label>
                        <Input
                            id="partner_name"
                            value={state.partnerName}
                            onChange={(e) =>
                                editor.update({ partnerName: e.target.value })
                            }
                            maxLength={120}
                            placeholder="e.g. Yorkshire Tea"
                        />
                        <InputError message={errors.partner_name} />
                    </div>
                    <div className="grid content-start gap-2">
                        <Label htmlFor="partner_handle">Their handle</Label>
                        <Input
                            id="partner_handle"
                            value={state.partnerHandle}
                            onChange={(e) =>
                                editor.update({ partnerHandle: e.target.value })
                            }
                            maxLength={64}
                            placeholder="@yorkshiretea"
                        />
                        <InputError message={errors.partner_handle} />
                    </div>
                    <div className="grid content-start gap-2">
                        <Label htmlFor="brand_deal_id">Deal</Label>
                        <Select
                            value={state.brandDealId || NO_DEAL}
                            onValueChange={(value) =>
                                editor.update({
                                    brandDealId: value === NO_DEAL ? '' : value,
                                })
                            }
                        >
                            <SelectTrigger id="brand_deal_id">
                                <SelectValue placeholder="Not linked" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NO_DEAL}>
                                    Not linked
                                </SelectItem>
                                {page.dealOptions.map((deal) => (
                                    <SelectItem
                                        key={deal.id}
                                        value={String(deal.id)}
                                    >
                                        {deal.partner_name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.brand_deal_id} />
                    </div>
                </div>
            )}
        </Panel>
    );
}
