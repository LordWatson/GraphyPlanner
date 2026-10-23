<?php

namespace App\Http\Requests;

use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Actions\Posts\PostStatusTransitionMap;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Services\Posts\PostWorkflowResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class TransitionPostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $to = PostStatus::tryFrom((string) $this->input('to'));

        if ($to === null) {
            // Let validation surface the invalid `to` value — but only to someone who can see the
            // post at all (Step I-1.9: another organization gets a 403, not validation errors).
            return $this->user()->can('view', $this->route('post'));
        }

        return $this->user()->can('transition', [$this->route('post'), $to]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to' => [
                'required',
                new Enum(PostStatus::class),
                function (string $attribute, mixed $value, \Closure $fail) {
                    /** @var Post $post */
                    $post = $this->route('post');
                    $to = PostStatus::tryFrom((string) $value);

                    if ($to !== null && PostStatusTransitionMap::needsClientApproval($post, $post->status, $to)) {
                        $fail(__('This post needs the client’s approval — send it to the client, or an Owner can override.'));

                        return;
                    }

                    // Step B-3.1: legality is the workspace's workflow, not the raw map — a business
                    // post may self-approve (`draft → approved`) where an agency post may not.
                    if ($to !== null && ! in_array($to, app(PostWorkflowResolver::class)->forPost($post)->allowedTransitions($post, $this->user()), true)) {
                        $fail("Cannot transition this post from {$post->status->value} to {$to->value}.");

                        return;
                    }

                    // §6 checklist must be re-validated server-side before any transition to
                    // `scheduled` (plan Step 0.10) — the client-side check alone isn't trusted.
                    if ($to === PostStatus::Scheduled) {
                        $checklist = (new EvaluatePostChecklistAction)($post);

                        if (! $checklist['passed']) {
                            $fail('The pre-schedule checklist must pass before this post can be scheduled.');
                        }
                    }
                },
            ],
            'comment' => ['nullable', 'string'],
        ];
    }
}
