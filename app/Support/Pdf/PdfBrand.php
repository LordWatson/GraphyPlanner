<?php

namespace App\Support\Pdf;

/**
 * Alima artwork for dompdf views (the invoice, Step 7.5, and the analytics report, Step 17.7):
 * the master mark on a Deep Teal tile and the teal wordmark, both read from the vector masters in
 * `public/brand` — never redrawn — and embedded as data URIs, since dompdf can't fetch assets.
 */
final class PdfBrand
{
    /**
     * Cream mark on a Deep Teal tile (40×40 viewBox, 22.5% radius), the mark at 70% of the tile.
     */
    public static function markTile(): string
    {
        $mark = (string) file_get_contents(public_path('brand/alima_mark_cream.svg'));
        preg_match('/viewBox="([^"]+)"/', $mark, $markBox);
        preg_match_all('/ d="([^"]+)"/', $mark, $markPaths);
        [, , $markW, $markH] = array_map('floatval', explode(' ', $markBox[1] ?? '0 0 1 1'));
        $markScale = 28 / max($markW, 1);

        return 'data:image/svg+xml;base64,'.base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40">'
            .'<rect width="40" height="40" rx="9" fill="#205557"/>'
            .'<g fill="#F5F2E8" transform="translate(6 '.round((40 - $markH * $markScale) / 2, 2).') scale('.round($markScale, 5).')">'
            .implode('', array_map(fn (string $d) => '<path d="'.$d.'"/>', $markPaths[1]))
            .'</g></svg>'
        );
    }

    /**
     * The horizontal lock-up in Deep Teal, for footers on cream paper.
     */
    public static function wordmark(): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode((string) file_get_contents(public_path('brand/alima_logo_teal.svg')));
    }

    /**
     * Absolute path to a bundled brand font (`resources/fonts/pdf`), forward-slashed for dompdf.
     */
    public static function font(string $file): string
    {
        return str_replace('\\', '/', resource_path('fonts/pdf').'/'.$file);
    }
}
