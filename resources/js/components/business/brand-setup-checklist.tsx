import { AtSign, Send, SwatchBook, Target } from 'lucide-react';
import { SetupChecklist, type SetupStep } from '@/components/setup-checklist';
import { index as accounts } from '@/routes/business/accounts';
import { edit as brand } from '@/routes/business/brand';
import { create as createPost } from '@/routes/business/posts';

export type BrandSetup = {
    accounts: boolean;
    brand: boolean;
    goal: boolean;
    post: boolean;
    complete: boolean;
};

/**
 * UX overhaul (business propagation) — the creator's "get set up" first-run on Today: connect an
 * account, set up the brand, set a posting goal, publish a first post. Mirrors the agency client
 * setup checklist on the shared `SetupChecklist`; hides itself once the brand is up and running.
 */
export function BrandSetupChecklist({
    setup,
    brandName,
}: {
    setup: BrandSetup;
    brandName: string;
}) {
    const steps: SetupStep[] = [
        {
            key: 'accounts',
            title: 'Connect an account',
            hint: 'Link the networks you post to.',
            cta: 'Connect',
            icon: AtSign,
            href: accounts(),
            done: setup.accounts,
        },
        {
            key: 'brand',
            title: 'Set up your brand',
            hint: 'Your voice, audience and look — so every post sounds like you.',
            cta: 'Open Brand',
            icon: SwatchBook,
            href: brand(),
            done: setup.brand,
        },
        {
            key: 'goal',
            title: 'Set a posting goal',
            hint: "How often you want to post — we'll flag the gaps.",
            cta: 'Set a goal',
            icon: Target,
            href: brand(),
            done: setup.goal,
        },
        {
            key: 'post',
            title: 'Publish your first post',
            hint: 'Draft one now — pick a time and we put it out.',
            cta: 'New post',
            icon: Send,
            href: createPost(),
            method: 'post',
            done: setup.post,
        },
    ];

    return (
        <SetupChecklist
            title={<>Get {brandName} ready to post.</>}
            steps={steps}
        />
    );
}
