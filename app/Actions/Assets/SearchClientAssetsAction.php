<?php

namespace App\Actions\Assets;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Client;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SearchClientAssetsAction
{
    public const PER_PAGE = 24;

    /**
     * Page through a client's asset library, newest first, optionally filtered by a filename
     * search and/or asset type. Backs the post editor's "Browse library" dialog so the editor never
     * has to load a client's whole library up front.
     *
     * @return LengthAwarePaginator<int, Asset>
     */
    public function __invoke(Client $client, ?string $search = null, ?AssetType $type = null, int $page = 1): LengthAwarePaginator
    {
        $search = trim((string) $search);

        return $client->assets()
            ->with('campaign:id,name')
            ->when($search !== '', function ($query) use ($search) {
                // Treat the user's input literally: `%` / `_` are not wildcards here.
                $escaped = addcslashes($search, '\\%_');
                $query->whereRaw("lower(original_filename) like ? escape '\\'", ['%'.mb_strtolower($escaped).'%']);
            })
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page);
    }
}
