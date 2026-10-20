import { Search } from 'lucide-react';
import type { LibraryScope } from '@/components/post-editor/asset-library/types';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

const typeFilters = [
    { value: 'all', label: 'All' },
    { value: 'image', label: 'Images' },
    { value: 'video', label: 'Videos' },
    { value: 'document', label: 'Documents' },
];

/** Search, campaign scope ("This campaign / Whole library") and type filters for the library. */
export function LibraryFilters({
    search,
    onSearchChange,
    scope,
    onScopeChange,
    showScope,
    type,
    onTypeChange,
    showType,
}: {
    search: string;
    onSearchChange: (search: string) => void;
    scope: LibraryScope;
    onScopeChange: (scope: LibraryScope) => void;
    showScope: boolean;
    type: string;
    onTypeChange: (type: string) => void;
    showType: boolean;
}) {
    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div className="relative flex-1">
                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input
                    type="search"
                    value={search}
                    onChange={(e) => onSearchChange(e.target.value)}
                    placeholder="Search by file name…"
                    className="pl-9"
                    aria-label="Search assets"
                    autoFocus
                />
            </div>
            {showScope && (
                <ToggleGroup
                    type="single"
                    variant="outline"
                    size="sm"
                    value={scope}
                    onValueChange={(value) => {
                        if (value === 'campaign' || value === 'all') {
                            onScopeChange(value);
                        }
                    }}
                    aria-label="Which assets"
                >
                    <ToggleGroupItem value="campaign" className="px-3">
                        This campaign
                    </ToggleGroupItem>
                    <ToggleGroupItem value="all" className="px-3">
                        Whole library
                    </ToggleGroupItem>
                </ToggleGroup>
            )}
            {showType && (
                <ToggleGroup
                    type="single"
                    variant="outline"
                    size="sm"
                    value={type}
                    onValueChange={(value) => value && onTypeChange(value)}
                    aria-label="Filter by type"
                >
                    {typeFilters.map((filter) => (
                        <ToggleGroupItem
                            key={filter.value}
                            value={filter.value}
                            className="px-3"
                        >
                            {filter.label}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            )}
        </div>
    );
}
