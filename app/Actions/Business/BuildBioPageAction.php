<?php

namespace App\Actions\Business;

use App\Enums\BioBlockType;
use App\Enums\PostStatus;
use App\Enums\ProductStatus;
use App\Models\BioBlock;
use App\Models\BioPage;
use App\Models\MediaKit;
use App\Models\Post;
use App\Models\Product;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Support\Str;

/**
 * Step CG-3.1 — assemble what a visitor sees on `/@{handle}`. Reused by the public page and by the
 * owner's live preview, so the editor shows exactly what ships.
 *
 * Privacy, as on the media kit: only visible blocks, only **published** posts, and a media-kit
 * block only while the owner's public kit link is on. Hidden blocks, drafts, scheduled posts and
 * anything internal never reach this payload — and the whole thing is for the owner's eyes only
 * until `published_at` is set (enforced by the public controller).
 *
 * @phpstan-type BioBlockPayload array<string, mixed>
 */
class BuildBioPageAction
{
    private const MAX_POSTS = 6;

    /**
     * @return array<string, mixed>
     */
    public function __invoke(BioPage $page): array
    {
        $brand = $page->client;

        return [
            'handle' => $page->handle,
            'title' => $page->title ?? $brand?->name,
            'bio' => $page->bio,
            'theme' => $page->theme->value,
            'brand_name' => $brand?->name,
            'blocks' => array_values(array_filter(array_map(
                fn (BioBlock $block) => $this->block($page, $block),
                $page->blocks()->where('is_visible', true)->get()->all(),
            ))),
        ];
    }

    /**
     * One rendered block, or null when it has nothing to show (an empty link, a media kit whose
     * public link is off, a brand with no published posts yet).
     *
     * @return array<string, mixed>|null
     */
    private function block(BioPage $page, BioBlock $block): ?array
    {
        $settings = $block->settings ?? [];

        $base = [
            'id' => $block->id,
            'type' => $block->type->value,
        ];

        return match ($block->type) {
            BioBlockType::Link, BioBlockType::MediaKit, BioBlockType::Product => $this->destination($page, $block) === null
                ? null
                : [
                    ...$base,
                    'label' => $settings['label'] ?? $this->defaultLabel($block),
                    // Clicks are counted on the way out (CG-3.3), never by a tracker on the page.
                    'url' => route('bio.click', ['handle' => $page->handle, 'block' => $block->id]),
                ],
            BioBlockType::LatestPosts => $this->latestPosts($page, (int) ($settings['limit'] ?? 3)),
            // Step GTM-2.1: the sign-up form. It posts to the page's own subscribe route rather
            // than carrying a destination, and the owner's preview renders it inert.
            BioBlockType::EmailCapture => [
                ...$base,
                'label' => $settings['label'] ?? 'Get my emails',
                'url' => route('bio.subscribe', ['handle' => $page->handle]),
            ],
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestPosts(BioPage $page, int $limit): ?array
    {
        $posts = Post::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('client_id', $page->client_id)
            ->whereIn('status', [PostStatus::Published, PostStatus::PartiallyPublished])
            ->with(['assets', 'targets'])
            ->latest('updated_at')
            ->limit(max(1, min($limit, self::MAX_POSTS)))
            ->get()
            ->map(fn (Post $post) => [
                'caption' => $post->master_caption !== null ? Str::limit(trim($post->master_caption), 90) : null,
                'thumbnail_url' => $post->assets->first()?->thumbnailUrl(),
                'url' => $post->targets->pluck('post_url')->filter()->first(),
            ])
            ->values()
            ->all();

        return $posts === [] ? null : ['id' => 0, 'type' => BioBlockType::LatestPosts->value, 'posts' => $posts];
    }

    /**
     * Where a clickable block actually sends the visitor, or null when it has no destination (an
     * empty link, or a media kit whose public link is off). The click route resolves it here rather
     * than putting the real URL on the page, so the count can never be bypassed by copying a link.
     */
    public function destination(BioPage $page, BioBlock $block): ?string
    {
        $settings = $block->settings ?? [];

        if ($block->type === BioBlockType::Link) {
            $url = trim((string) ($settings['url'] ?? ''));

            return $url === '' ? null : $url;
        }

        // Step CG-4.2: a product block points at the product's own page on this handle, and only
        // while the product is live — an archived or drafted product drops off the page by itself.
        if ($block->type === BioBlockType::Product) {
            $product = Product::query()
                ->withoutOrgScope()
                ->where('client_id', $page->client_id)
                ->where('status', ProductStatus::Live)
                ->find((int) ($settings['product_id'] ?? 0));

            return $product === null
                ? null
                : route('store.product', ['handle' => $page->handle, 'product' => $product->id]);
        }

        if ($block->type === BioBlockType::MediaKit) {
            $kit = MediaKit::query()->where('client_id', $page->client_id)->first();

            return $kit?->isPublic() ? route('media-kit.show', $kit->share_token) : null;
        }

        return null;
    }

    private function defaultLabel(BioBlock $block): string
    {
        if ($block->type === BioBlockType::MediaKit) {
            return 'My media kit';
        }

        if ($block->type === BioBlockType::Product) {
            return (string) (Product::query()
                ->withoutOrgScope()
                ->whereKey((int) ($block->settings['product_id'] ?? 0))
                ->value('title') ?? $block->type->label());
        }

        return (string) ($block->settings['url'] ?? $block->type->label());
    }
}
