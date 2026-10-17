/** Prop shapes sent by `ImageStudioPresenter` (Phase 16) as the `imageStudio` Inertia prop. */

export type StudioFormat = {
    value: string;
    label: string;
    quotient: number;
};

export type StudioColour = { name: string; hex: string | null };

export type StudioReference = {
    assetId: number;
    url: string | null;
    role: string;
    roleLabel: string;
};

export type StudioArtDirection = {
    subject: string | null;
    mood: string | null;
    style: string | null;
    composition: string | null;
    excludedColours: string[];
    withHeadline: boolean;
    headline: string | null;
};

export type StudioVariant = {
    id: number;
    position: number;
    kind: 'generate' | 'refine' | 'regenerate' | 'final';
    kindLabel: string;
    status: 'queued' | 'generating' | 'ready' | 'failed';
    statusLabel: string;
    tier: 'draft' | 'final';
    parentId: number | null;
    instruction: string | null;
    previewUrl: string | null;
    url: string | null;
    assetId: number | null;
    width: number | null;
    height: number | null;
    alt: string;
    error: string | null;
    selected: boolean;
    discarded: boolean;
    pending: boolean;
    ready: boolean;
};

export type StudioGeneration = {
    id: number;
    status: string;
    statusLabel: string;
    settled: boolean;
    format: string;
    formatLabel: string;
    quotient: number;
    variantCount: number;
    requestedBy: string | null;
    createdAt: string | null;
    artDirection: StudioArtDirection;
    overrides: {
        subject: string | null;
        mood: string | null;
        style: string | null;
        composition: string | null;
    };
    variants: StudioVariant[];
};

export type ImageStudioData = {
    canGenerate: boolean;
    target: {
        type: 'idea' | 'post';
        ideaId: number | null;
        postId: number | null;
        clientId: number;
        clientName: string;
        title: string;
        caption: string | null;
        platforms: string[];
    };
    formats: StudioFormat[];
    defaultFormat: string;
    maxVariants: number;
    quota: { used: number; quota: number | null; remaining: number | null };
    brandDefaults: {
        subject: string;
        mood: string;
        style: string;
        composition: string;
    };
    palette: StudioColour[];
    references: StudioReference[];
    generations: StudioGeneration[];
};
