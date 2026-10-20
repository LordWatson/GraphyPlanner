import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { latestEmail, linkIn } from './support/mail';

/**
 * Step I-14.6 / M-1.5 — the first critical journey: a new agency signs up, verifies its email,
 * adds a client and a channel, writes a post, takes it through review and approval, and
 * schedules it. Nothing reaches a publishing vendor: the queue isn't worked in this environment.
 */

const stamp = Date.now();
const owner = {
    organization: `Northlight Studio ${stamp}`,
    name: 'Ada Lovelace',
    email: `ada+${stamp}@northlight.test`,
    password: 'correct-horse-battery-staple-42',
};

/** A date a few days ahead, for the calendar picker. */
function daysAhead(days: number): Date {
    const date = new Date();
    date.setDate(date.getDate() + days);

    return date;
}

async function pickDate(page: Page, trigger: string | RegExp, date: Date) {
    await page.getByRole('button', { name: trigger }).first().click();
    const calendar = page.locator('[data-slot="calendar"]');
    const month = date.toLocaleDateString('en-US', { month: 'long' });
    // Day buttons are named like "Friday, October 23rd, 2026".
    const day = calendar.getByRole('button', {
        name: new RegExp(
            `${month} ${date.getDate()}(st|nd|rd|th), ${date.getFullYear()}`,
        ),
    });

    // Step forward until the target day is on show.
    for (let i = 0; i < 12 && !(await day.count()); i++) {
        await calendar.getByRole('button', { name: /next/i }).click();
    }

    await day.first().click();
}

async function selectOption(
    page: Page,
    label: string | RegExp,
    option: string,
) {
    await page.getByLabel(label).click();
    await page.getByRole('option', { name: option, exact: true }).click();
}

test('a new agency signs up and schedules its first post', async ({ page }) => {
    // --- Sign up -------------------------------------------------------------------------
    await page.goto('/register-organization');
    await page
        .getByLabel(/organization name|studio name|agency name/i)
        .fill(owner.organization);
    await page.getByLabel('Your name').fill(owner.name);
    await page.getByLabel('Work email').fill(owner.email);
    await page.getByLabel('Password', { exact: true }).fill(owner.password);
    await page.getByLabel(/confirm password/i).fill(owner.password);
    await page.getByRole('button', { name: /create|start|sign up/i }).click();

    // --- Verify the email ----------------------------------------------------------------
    await expect(page).toHaveURL(/verify-email|email\/verify/);
    let email: string | null = null;
    await expect
        .poll(() => (email = latestEmail(owner.email, 'verify')), {
            message: 'verification email',
        })
        .not.toBeNull();
    const verifyUrl = linkIn(email!, 'email/verify/');
    expect(verifyUrl).not.toBeNull();
    await page.goto(verifyUrl!.replace(/^https?:\/\/[^/]+/, ''));
    await expect(page).toHaveURL(/dashboard/);

    // --- Add a client and a channel --------------------------------------------------------
    await page.goto('/clients/create');
    await page.getByLabel('Name', { exact: true }).fill('Harbour Coffee');
    await page
        .getByRole('button', { name: /create client|add client|save/i })
        .click();
    await expect(page).toHaveURL(/\/clients\/\d+$/);

    await selectOption(page, 'Platform', 'LinkedIn');
    await page.getByLabel('Handle', { exact: true }).fill('harbour-coffee');
    await selectOption(page, 'Timezone', 'Europe/London');
    await page.getByRole('button', { name: 'Add social account' }).click();
    await expect(page.getByText('harbour-coffee').first()).toBeVisible();

    // --- Write the post ------------------------------------------------------------------
    await page.goto(page.url() + '/posts');
    await page.getByRole('button', { name: 'New post' }).click();
    const dialog = page.getByRole('dialog', { name: 'New post' });
    await dialog
        .getByLabel('Caption')
        .fill('Autumn menu lands Friday. Spiced oat flat white, anyone?');
    await dialog.getByText('harbour-coffee').click();
    const publishOn = daysAhead(3);
    await pickDate(page, /date/i, publishOn);
    await dialog.getByLabel(/time/i).fill('09:30');
    await dialog.getByRole('button', { name: 'Create post' }).click();
    await expect(page).toHaveURL(/\/posts\/\d+\/edit/);

    // --- Review, approve, schedule ------------------------------------------------------------
    // Each move opens a confirm dialog (with an optional note) before the status changes.
    const move = async (label: RegExp) => {
        await page.getByRole('button', { name: label }).first().click();
        const confirm = page.getByRole('dialog');
        await confirm.getByRole('button', { name: label }).click();
        await expect(confirm).toBeHidden();
    };

    await move(/^start drafting/i);
    await move(/^send for internal review/i);

    // The client approves by default; as the Owner, approve on their behalf with a reason.
    await page.getByRole('button', { name: 'Approve without client' }).click();
    await page.getByLabel('Reason').fill('Approved on our kick-off call.');
    await page.getByRole('button', { name: 'Approve post' }).click();
    await expect(page.getByRole('dialog')).toBeHidden();

    await move(/^schedule post/i);

    // Scheduled: the workflow says so, and the channel keeps its own local time.
    await expect(
        page.getByLabel('Post workflow').locator('[aria-current="step"]'),
    ).toContainText('Scheduled');
    await expect(
        page.getByText('Scheduled', { exact: true }).first(),
    ).toBeVisible();
});
