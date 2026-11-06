<?php

namespace App\Http\Controllers\Store;

use App\Contracts\AssetStorage;
use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Step CG-4.3 — the buyer's download. The route is **signed and expiring** (the signature is checked
 * by middleware before this runs), and three further things are checked here on every hit, because a
 * link that is still in an inbox must stop working the moment any of them turns:
 *
 *  - the order must be paid and not refunded ({@see Order::canDownload()});
 *  - the per-product use limit must not be spent;
 *  - the product must still have a file attached.
 *
 * The bytes are streamed through this route, so the file's own storage URL (a private disk) is never
 * handed out and every hit can be counted.
 */
class DownloadController extends Controller
{
    public function __invoke(Order $order, AssetStorage $storage): Response
    {
        $order->loadMissing('product.downloadAsset');
        $product = $order->product;
        $asset = $product->downloadAsset;

        abort_unless($product->type === ProductType::Download && $asset !== null, 404);
        abort_unless($order->canDownload(), 410, __('This download is no longer available.'));
        abort_if($order->download_count >= $product->downloadLimit(), 410, __('This link has been used its maximum number of times.'));

        $contents = $storage->contents($asset->disk, $asset->path);

        abort_if($contents === null, 404);

        $order->increment('download_count');
        $order->recordEvent('download.served', ['count' => $order->download_count]);

        Log::info('Store download served', [
            'order_id' => $order->id,
            'org_id' => $order->org_id,
            'product_id' => $product->id,
            'count' => $order->download_count,
        ]);

        return response($contents, 200, [
            'Content-Type' => $asset->mime_type ?? 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.addslashes($asset->original_filename ?? 'download').'"',
            // Never let a proxy or browser keep a copy of paid content.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
