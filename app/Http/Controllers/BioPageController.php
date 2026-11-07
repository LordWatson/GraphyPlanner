<?php

namespace App\Http\Controllers;

use App\Actions\Audience\CaptureAudienceContactAction;
use App\Actions\Business\BuildBioPageAction;
use App\Actions\Business\RecordBioEventAction;
use App\Enums\AudienceSource;
use App\Enums\BioBlockType;
use App\Mail\AudienceConfirmMail;
use App\Models\BioBlock;
use App\Models\BioPage;
use App\Models\BioPageDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step CG-3.1 / CG-3.3 — the public link in bio at `/@{handle}`, and the counted click-through at
 * `/@{handle}/go/{block}`. No auth: the handle is the whole address. An unpublished page, or one
 * whose brand has been deleted, 404s — exactly like a revoked media-kit link.
 */
class BioPageController extends Controller
{
    public function show(Request $request, string $handle, BuildBioPageAction $build, RecordBioEventAction $record): Response
    {
        $page = $this->resolve($handle);

        $record($request, $page);

        return Inertia::render('public/bio', ['page' => $build($page)]);
    }

    /**
     * Count the click, then send the visitor on. The destination is resolved server-side
     * ({@see BuildBioPageAction::destination()}) so the public page never carries the real URL and
     * the count can't be skipped.
     */
    public function click(Request $request, string $handle, int $block, BuildBioPageAction $build, RecordBioEventAction $record): RedirectResponse
    {
        $page = $this->resolve($handle);

        $found = BioBlock::query()
            ->where('bio_page_id', $page->id)
            ->where('is_visible', true)
            ->find($block);

        abort_if($found === null, 404);

        $destination = $build->destination($page, $found);

        abort_if($destination === null, 404);

        $record($request, $page, $found->id);

        return redirect()->away($destination);
    }

    /**
     * Step GTM-3.2 — the app's root. On our own host it is the marketing page; on a **verified**
     * custom domain it is that creator's bio page, which is the whole of custom-domain serving.
     *
     * It lives here rather than in a middleware because only this one path changes: every other
     * route matches on a custom host exactly as it does on ours, and Laravel generates URLs
     * against the current request, so the page's own links stay on the creator's domain.
     *
     * An unverified domain falls through to the marketing page — claiming a hostname serves
     * nothing until DNS actually points here.
     */
    public function home(Request $request, BuildBioPageAction $build, RecordBioEventAction $record): Response
    {
        $host = BioPageDomain::normaliseHostname($request->getHost());

        $domain = $host === BioPageDomain::normaliseHostname((string) parse_url((string) config('app.url'), PHP_URL_HOST))
            ? null
            : BioPageDomain::query()->whereNotNull('verified_at')->where('hostname', $host)->first();

        $page = $domain?->page;

        if ($page === null || ! $page->isPublished() || $page->client === null) {
            return Inertia::render('welcome');
        }

        $record($request, $page);

        return Inertia::render('public/bio', ['page' => $build($page)]);
    }

    /**
     * Step GTM-2.1 — somebody signing up from an `email_capture` block. **Double opt-in**: this
     * only ever creates an unconfirmed contact and emails them a confirmation link; following that
     * link is the consent. A honeypot (`website`) and the group throttle guard the write.
     *
     * The response is the same whether the address was new, already on the list, already
     * unsubscribed or caught by the honeypot — the form must not be usable to find out who is on
     * somebody's list.
     */
    public function subscribe(Request $request, string $handle, CaptureAudienceContactAction $capture): RedirectResponse
    {
        $page = $this->resolve($handle);

        $validated = $request->validate([
            'email' => ['required', 'email:filter', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'string', 'max:255'],
        ]);

        $hasBlock = $page->blocks()
            ->where('type', BioBlockType::EmailCapture)
            ->where('is_visible', true)
            ->exists();

        if ($hasBlock && blank($validated['website'] ?? null)) {
            $contact = $capture(
                $page->client,
                $validated['email'],
                $validated['name'] ?? null,
                AudienceSource::BioCapture,
            );

            // An already-confirmed or unsubscribed contact is left exactly as it is: no second
            // confirmation email, and nothing that could be used to pester an unsubscriber.
            if ($contact->consent_at === null && $contact->unsubscribed_at === null) {
                Mail::to($contact->email)->send(new AudienceConfirmMail($contact));
            }
        } else {
            Log::info('Bio page sign-up ignored', [
                'client_id' => $page->client_id,
                'reason' => $hasBlock ? 'honeypot' : 'no visible block',
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Check your inbox — there is a link to confirm.'),
        ]);
    }

    private function resolve(string $handle): BioPage
    {
        $page = BioPage::query()->where('handle', strtolower($handle))->first();

        abort_if($page === null || ! $page->isPublished() || $page->client === null, 404);

        return $page;
    }
}
