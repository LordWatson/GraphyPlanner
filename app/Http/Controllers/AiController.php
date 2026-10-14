<?php

namespace App\Http\Controllers;

use App\Actions\AI\DiscoverTrendsAction;
use App\Actions\AI\RecommendPostsAction;
use App\Actions\AI\SuggestCaptionsForPostAction;
use App\Enums\Platform;
use App\Models\Client;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class AiController extends Controller
{
    public function trends(Request $request, Client $client, DiscoverTrendsAction $action)
    {
        abort_unless((bool) config('features.ai', false), 404);

        $platformValues = array_values(Arr::wrap($request->query('platforms', [])));
        $platforms = array_values(array_filter(array_map(function ($val) {
            try {
                return Platform::from((string) $val);
            } catch (\ValueError) {
                return null;
            }
        }, $platformValues)));

        $trends = $action($client, $platforms);
        return response()->json($trends);
    }

    public function ideas(Client $client, RecommendPostsAction $action)
    {
        abort_unless((bool) config('features.ai', false), 404);

        $ideas = $action($client);
        return response()->json($ideas);
    }

    public function captions(Post $post, SuggestCaptionsForPostAction $action)
    {
        abort_unless((bool) config('features.ai', false), 404);

        $suggestions = $action($post);
        return response()->json($suggestions);
    }
}
