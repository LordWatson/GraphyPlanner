/**
 * Step B-1.3 / B-4 — the shared workspace shell prop (`HandleInertiaRequests::sharedWorkspace`).
 * `type` switches the sidebar, command palette and copy between the agency and business shells;
 * `brand` is a business workspace's single primary brand (null for an agency); `roleLabels` maps
 * each stored role to the label this workspace shows ("Manager"/"Creator" for business).
 */
export type WorkspaceType = 'agency' | 'business';

export type Workspace = {
    type: WorkspaceType;
    brand: { id: number; name: string } | null;
    roleLabels: Record<string, string>;
};
