import type { WorkspaceType } from '@/types';

/**
 * Step B-4.4 — the one place agency vocabulary is translated for the business shell, so shared
 * components that render a term ("client", "campaign", "internal review") read the right word for
 * the workspace they're in. A business user never sees "client", "retainer", "portal" or "invoice";
 * their brand is "your brand".
 *
 * Keep this in step with the PHP equivalent (`App\Support\Workspace\WorkspaceCopy`) used by mails
 * and notifications.
 */
const BUSINESS_TERMS: Record<string, string> = {
    client: 'brand',
    Client: 'Brand',
    clients: 'brands',
    Clients: 'Brands',
    campaign: 'collection',
    Campaign: 'Collection',
    campaigns: 'collections',
    Campaigns: 'Collections',
    'internal review': 'review',
    'Internal review': 'Review',
    staff: 'team',
    Staff: 'Team',
};

/**
 * Translate a single term for the given workspace. Unknown terms pass through unchanged, so callers
 * can wrap any label safely.
 */
export function term(word: string, type: WorkspaceType | undefined): string {
    if (type !== 'business') {
        return word;
    }

    return BUSINESS_TERMS[word] ?? word;
}

/**
 * The shared terms a component is likely to need, resolved for the workspace once. Agency values
 * are the defaults; a business workspace gets the translated set.
 */
export function workspaceCopy(type: WorkspaceType | undefined): {
    client: string;
    clients: string;
    campaign: string;
    collection: string;
    campaigns: string;
    review: string;
} {
    const business = type === 'business';

    return {
        client: business ? 'brand' : 'client',
        clients: business ? 'brands' : 'clients',
        campaign: business ? 'collection' : 'campaign',
        collection: business ? 'collection' : 'campaign',
        campaigns: business ? 'collections' : 'campaigns',
        review: 'review',
    };
}
