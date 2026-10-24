import { NotebookPen, Send, Share2, UserPlus } from 'lucide-react';
import type { ClientPageProps } from '@/components/clients/client-layout';
import { SetupChecklist, type SetupStep } from '@/components/setup-checklist';
import { portalAccess as clientPortalAccess, show } from '@/routes/clients';
import { edit as editBrandBrain } from '@/routes/clients/brand-brain';
import { index as clientPosts } from '@/routes/clients/posts';

/**
 * MOVE 5 (ux-overhaul): the guided first-run for a new client. It greets the studio on the client's
 * landing (accounts) tab and walks the four steps to a first post — Brand Brain, accounts, the
 * portal invite, and the first post/campaign — ticking each off from the server `setup` signals and
 * showing only the steps the viewer can act on. Built on the shared `SetupChecklist`.
 */
export function ClientSetupChecklist({
    client,
    can,
    setup,
}: {
    client: ClientPageProps['client'];
    can: ClientPageProps['can'];
    setup: ClientPageProps['setup'];
}) {
    const steps: SetupStep[] = [
        {
            key: 'brandBrain',
            title: 'Fill in the Brand Brain',
            hint: 'Teach the studio its voice, audience and look.',
            cta: 'Open Brand Brain',
            icon: NotebookPen,
            href: editBrandBrain(client.id),
            done: setup.brandBrain,
            visible: can.update,
        },
        {
            key: 'accounts',
            title: 'Connect social accounts',
            hint: "Link the networks you'll publish to — or send a connect link.",
            cta: 'Connect',
            icon: Share2,
            href: show(client.id),
            done: setup.accounts,
            visible: can.createSocialAccount,
        },
        {
            key: 'portal',
            title: 'Invite the client',
            hint: 'Give them a portal login to review and approve posts.',
            cta: 'Invite',
            icon: UserPlus,
            href: clientPortalAccess(client.id),
            done: setup.portal,
            visible: can.createInvitation,
        },
        {
            key: 'content',
            title: 'Create the first post',
            hint: 'Draft a post or plan a campaign.',
            cta: 'Start a post',
            icon: Send,
            href: clientPosts(client.id),
            done: setup.content,
            visible: can.createPost || can.createCampaign,
        },
    ];

    return (
        <SetupChecklist
            title={<>The quickest path to {client.name}&rsquo;s first post.</>}
            steps={steps}
        />
    );
}
