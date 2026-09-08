<?php

namespace App\Http\Controllers;

use App\Actions\Posts\CreatePostAction;
use App\Http\Requests\StorePostRequest;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;

class PostController extends Controller
{
    /**
     * Create a new post (with its per-account targets) for the given client.
     */
    public function store(StorePostRequest $request, Client $client, CreatePostAction $action): RedirectResponse
    {
        $action($client, $request->validated());

        return to_route('clients.show', $client);
    }
}
