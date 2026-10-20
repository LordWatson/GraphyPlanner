import type { AssetData } from '@/components/post-editor/types';

export type LibraryAsset = AssetData & {
    campaign_name: string | null;
    created_at: string | null;
    can?: { delete: boolean };
};

export type LibraryPage = {
    data: LibraryAsset[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

export type LibraryScope = 'campaign' | 'all';
