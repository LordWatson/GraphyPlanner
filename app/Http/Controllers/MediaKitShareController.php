<?php

namespace App\Http\Controllers;

use App\Actions\Business\BuildMediaKitAction;
use App\Actions\Business\GenerateMediaKitPdfAction;
use App\Models\MediaKit;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Step B-8.4 — the public, tokenised media kit. No auth: anyone holding a live link sees the kit;
 * the moment the owner revokes it (token cleared) the link 404s. Only ever renders the public kit
 * data ({@see BuildMediaKitAction} — published posts and public follower counts, never emails,
 * drafts or internal notes).
 */
class MediaKitShareController extends Controller
{
    public function show(string $token, BuildMediaKitAction $build): Response
    {
        $kit = $this->resolve($token);

        return Inertia::render('share/media-kit', [
            'kit' => $build($kit, $kit->client),
            'pdfUrl' => route('media-kit.share.pdf', $token),
        ]);
    }

    public function pdf(string $token, BuildMediaKitAction $build, GenerateMediaKitPdfAction $pdf): StreamedResponse
    {
        $kit = $this->resolve($token);
        $bytes = $pdf($build($kit, $kit->client));

        return ResponseFactory::streamDownload(
            fn () => print ($bytes),
            GenerateMediaKitPdfAction::filename($kit->client),
            ['Content-Type' => 'application/pdf'],
        );
    }

    private function resolve(string $token): MediaKit
    {
        $kit = MediaKit::query()->where('share_token', $token)->first();

        abort_if($kit === null || $kit->client === null, 404);

        return $kit;
    }
}
