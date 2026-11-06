<?php

namespace App\Http\Controllers;

use App\Actions\Business\BuildMediaKitAction;
use App\Actions\Business\GenerateMediaKitPdfAction;
use App\Actions\Deals\RecordDealEnquiryAction;
use App\Models\MediaKit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            'enquiryUrl' => $kit->acceptsEnquiries() ? route('media-kit.enquire', $token) : null,
        ]);
    }

    /**
     * Step CG-5.3 — "work with me": a sponsor's enquiry becomes a draft deal on the creator's
     * tracker ({@see RecordDealEnquiryAction}). Three defences, because this is a public write:
     * the owner has to have switched the form on, the route is throttled, and a filled `website`
     * field is a honeypot (a real visitor never sees it, a bot fills everything).
     *
     * The answer is the same either way, so the form can't be used to probe which kits are live.
     */
    public function enquire(Request $request, string $token, RecordDealEnquiryAction $record): RedirectResponse
    {
        $kit = $this->resolve($token);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:filter', 'max:255'],
            'company' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:2000'],
            'website' => ['nullable', 'string', 'max:255'],
        ]);

        if ($kit->acceptsEnquiries() && blank($validated['website'] ?? null)) {
            $record($kit, $validated);
        } else {
            Log::info('Media kit enquiry ignored', [
                'client_id' => $kit->client_id,
                'reason' => $kit->acceptsEnquiries() ? 'honeypot' : 'form off',
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Thanks — your message is with them.'),
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
