<?php

use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\SocialAccountController;
use App\Http\Controllers\Api\V1\WebhookEndpointController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public REST API — v1
|--------------------------------------------------------------------------
|
| Authenticated with the same bearer tokens as the MCP transport (one token concept, not two: see
| `McpToken`). The organization comes from the token and never from request input, so a token can
| only ever see its own organization's data — the same rule the web UI gets from
| `OrganizationScope`, reached the same way.
|
| **Read-only in v1, deliberately.** Reading is where the demand is (reporting, warehouses, a CRM
| that wants to know what went out) and it cannot break a customer's data while the shape of the
| API is still settling. Writes are a v1.1 decision with idempotency keys to design; outgoing
| webhooks below already cover "tell my system when something happens", which is most of what a
| write API gets asked for.
|
| Everything here is versioned in the path, cursor-paginated, and rate limited per token.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    // What this token is and what it may do. First call anyone makes when a request 403s, and the
    // cheapest possible way for a client to confirm their credentials work.
    Route::get('me', MeController::class)->middleware('api.token:read')->name('me');

    Route::middleware('api.token:read')->group(function () {
        Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
        Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');

        Route::get('posts', [PostController::class, 'index'])->name('posts.index');
        Route::get('posts/{post}', [PostController::class, 'show'])->name('posts.show');

        Route::get('social-accounts', [SocialAccountController::class, 'index'])->name('social-accounts.index');
    });

    // Outgoing webhooks: the subscription side of "tell my system when something happens". Managed
    // over the API itself so an integration can set itself up without a person in the UI, which is
    // what a Zapier-style connector needs.
    Route::middleware('api.token:read')->group(function () {
        Route::get('webhooks', [WebhookEndpointController::class, 'index'])->name('webhooks.index');
        Route::get('webhooks/{endpoint}', [WebhookEndpointController::class, 'show'])->name('webhooks.show');
    });

    Route::middleware('api.token:write:drafts')->group(function () {
        Route::post('webhooks', [WebhookEndpointController::class, 'store'])->name('webhooks.store');
        Route::delete('webhooks/{endpoint}', [WebhookEndpointController::class, 'destroy'])->name('webhooks.destroy');
    });
});

/*
|--------------------------------------------------------------------------
| The spec
|--------------------------------------------------------------------------
|
| Unauthenticated on purpose: a developer reading the documentation has not been given a token yet,
| and nothing here is secret — it describes the shape of the API, not anybody's data. Throttled per
| IP like any other public route.
|
*/

Route::get('v1/openapi.json', function () {
    $path = resource_path('api/openapi.json');

    abort_unless(is_file($path), 404);

    return response()->file($path, ['Content-Type' => 'application/json']);
})->middleware('throttle:30,1')->name('api.v1.openapi');
