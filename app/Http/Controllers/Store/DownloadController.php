<?php

namespace App\Http\Controllers\Store;

use App\Contracts\AssetStorage;
use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Step CG-4.3 — the buyer's download. The route is **signed and expiring** (the signature is checked
 * by middleware before this runs), and three further things are checked here on every hit, because a
 * link that is still in an inbox must stop working the moment any of them turns:
 *
 *  - the order must be paid and not refunded ({@see Order::canDownload()});
 *  - the per-product use limit must not be spent;
 *  - the product must still have a file attached, on a private disk, virus-scanned
 *    ({@see Asset::downloadRefusalReason()}).
 *
 * The bytes are streamed through this route, so the file's own storage URL (a private disk) is never
 * handed out and every hit can be counted. Step GTM-1.1: streamed, not read into memory — the same
 * route serves a 500MB file from S3.
 */
class DownloadController extends Controller
{
    public function __invoke(Order $order, AssetStorage $storage): StreamedResponse
    {
        $order->loadMissing('product.downloadAsset');
        $product = $order->product;
        $asset = $product->downloadAsset;

        abort_unless($product->type === ProductType::Download && $asset !== null, 404);
        abort_unless($order->canDownload(), 410, __('This download is no longer available.'));
        abort_if($order->download_count >= $product->downloadLimit(), 410, __('This link has been used its maximum number of times.'));

        if (($refusal = $asset->downloadRefusalReason()) !== null) {
            Log::warning('Store download refused', [
                'order_id' => $order->id,
                'org_id' => $order->org_id,
                'product_id' => $product->id,
                'asset_id' => $asset->id,
                'reason' => $refusal,
            ]);

            abort(410, __('This download is no longer available.'));
        }

        $stream = $storage->readStream((string) $asset->disk, (string) $asset->path);

        abort_if($stream === null, 404);

        $order->increment('download_count');
        $order->recordEvent('download.served', ['count' => $order->download_count]);

        Log::info('Store download served', [
            'order_id' => $order->id,
            'org_id' => $order->org_id,
            'product_id' => $product->id,
            'count' => $order->download_count,
        ]);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $asset->mime_type ?? 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.addslashes($asset->original_filename ?? 'download').'"',
            // Never let a proxy or browser keep a copy of paid content.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
