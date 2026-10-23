import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { latestEmail, linkIn } from './support/mail';

/**
 * Step M-6.6 (I-8.6) — the client approves via the portal, on a phone viewport (the `mobile`
 * Playwright project runs this spec at Pixel 5 size). An agency sends a post for client review and
 * invites a contact; the contact accepts the invite (which signs them straight into the portal,
 * Step M-6.2) and approves the post from their phone.
 */

const stamp = Date.now();
const owner = {
    organization: `Seaside Studio ${stamp}`,
    name: 'Grace Hopper',
    email: `grace+${stamp}@seaside.test`,
    password: 'correct-horse-battery-staple-42',
};
const contactEmail = `client+${stamp}@seaside.test`;

async function selectOption(
    page: Page,
    label: string | RegExp,
    option: string,
) {
    await page.getByLabel(label).click();
    await page.getByRole('option', { name: option, exact: true }).click();
}

/** A workflow move: click the action, confirm in the dialog. */
async function move(page: Page, label: RegExp) {
    await page.getByRole('button', { name: label }).first().click();
    const confirm = page.getByRole('dialog');
    await confirm.getByRole('button', { name: label }).click();
    await expect(confirm).toBeHidden();
}

test('a client approves a post from their phone via the portal', async ({
    page,
}) => {
    // --- Agency signs up and verifies ----------------------------------------------------
    await page.goto('/register-organization');
    await page
        .getByLabel(/organization name|studio name|agency name/i)
        .fill(owner.organization);
    await page.getByLabel('Your name').fill(owner.name);
    await page.getByLabel('Work email').fill(owner.email);
    await page.getByLabel('Password', { exact: true }).fill(owner.password);
    await page.getByLabel(/confirm password/i).fill(owner.password);
    await page.getByRole('button', { name: /create|start|sign up/i }).click();

    await expect(page).toHaveURL(/verify-email|email\/verify/);
    let verifyEmail: string | null = null;
    await expect
        .poll(() => (verifyEmail = latestEmail(owner.email, 'verify')))
        .not.toBeNull();
    const verifyUrl = linkIn(verifyEmail!, 'email/verify/');
    await page.goto(verifyUrl!.replace(/^https?:\/\/[^/]+/, ''));
    await expect(page).toHaveURL(/dashboard/);

    // --- Client, channel, post -----------------------------------------------------------
    await page.goto('/clients/create');
    await page.getByLabel('Name', { exact: true }).fill('Dune Bakery');
    await page
        .getByRole('button', { name: /create client|add client|save/i })
        .click();
    await expect(page).toHaveURL(/\/clients\/(\d+)$/);
    const clientUrl = page.url();

    await selectOption(page, 'Platform', 'LinkedIn');
    await page.getByLabel('Handle', { exact: true }).fill('dune-bakery');
    await selectOption(page, 'Timezone', 'Europe/London');
    await page.getByRole('button', { name: 'Add social account' }).click();
    await expect(page.getByText('dune-bakery').first()).toBeVisible();

    await page.goto(clientUrl + '/posts');
    await page.getByRole('button', { name: 'New post' }).click();
    const dialog = page.getByRole('dialog', { name: 'New post' });
    await dialog.getByLabel('Caption').fill('Sourdough is back on Saturdays.');
    await dialog.getByText('dune-bakery').click();
    await dialog.getByRole('button', { name: 'Create post' }).click();
    await expect(page).toHaveURL(/\/posts\/\d+\/edit/);

    // --- Send it to the client -----------------------------------------------------------
    await move(page, /^start drafting/i);
    await move(page, /^send for internal review/i);
    await move(page, /^send to client/i);

    // --- Invite the client contact to the portal -----------------------------------------
    await page.goto(clientUrl + '/portal-access');
    await page
        .getByRole('textbox', { name: /email/i })
        .first()
        .fill(contactEmail);
    await page.getByRole('button', { name: /send invitation/i }).click();

    let inviteEmail: string | null = null;
    await expect
        .poll(() => (inviteEmail = latestEmail(contactEmail)))
        .not.toBeNull();
    const acceptUrl = linkIn(inviteEmail!, 'client-invite/');
    expect(acceptUrl).not.toBeNull();

    // --- The client accepts (signs straight into the portal) and approves ----------------
    await page.goto(acceptUrl!.replace(/^https?:\/\/[^/]+/, ''));
    await page.getByLabel('Your name').fill('Paul Atreides');
    await page.getByLabel('Password', { exact: true }).fill(owner.password);
    await page.getByLabel(/confirm password/i).fill(owner.password);
    await page
        .getByRole('button', {
            name: /create account|accept|continue|set password/i,
        })
        .click();

    await expect(page).toHaveURL(/\/portal/);

    // Open the post waiting on them and approve it.
    await page.goto('/portal/posts');
    await page.getByText('Sourdough is back on Saturdays.').first().click();
    await expect(page).toHaveURL(/\/portal\/posts\/\d+/);

    await page.getByRole('button', { name: 'Approve', exact: true }).click();

    await expect(page.getByText(/approved/i).first()).toBeVisible();
});
