<?php

namespace App\Http\Requests;

use App\Enums\CommentAnchorType;
use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Step M-6.4 — a post comment, optionally pinned to the media/caption, carrying a suggested caption
 * edit, or a reply inside a thread. Shared by the internal editor, the client portal and (via its
 * own route) the review link.
 */
class StorePostCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('comment', $this->route('post'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Post $post */
        $post = $this->route('post');
        $type = $this->input('anchor_type');

        return [
            'body' => ['required', 'string', 'max:5000'],
            'internal_only' => ['nullable', 'boolean'],
            'anchor_type' => ['nullable', new Enum(CommentAnchorType::class)],
            // A reply is a plain comment inside an existing root thread on this post.
            'parent_id' => [
                'nullable',
                Rule::exists('post_comments', 'id')->where('post_id', $post->id)->whereNull('parent_id'),
            ],
            'suggested_edit' => ['nullable', 'string', 'max:5000'],

            // Image / video pins reference one of the post's own assets.
            'anchor.asset_id' => [
                Rule::requiredIf(in_array($type, ['image', 'video'], true)),
                Rule::exists('assets', 'id')->where('client_id', $post->client_id),
            ],
            'anchor.x' => [Rule::requiredIf($type === 'image'), 'nullable', 'numeric', 'between:0,1'],
            'anchor.y' => [Rule::requiredIf($type === 'image'), 'nullable', 'numeric', 'between:0,1'],
            'anchor.t' => [Rule::requiredIf($type === 'video'), 'nullable', 'numeric', 'min:0'],

            // Caption-range pins.
            'anchor.start' => [Rule::requiredIf($type === 'caption'), 'nullable', 'integer', 'min:0'],
            'anchor.end' => [Rule::requiredIf($type === 'caption'), 'nullable', 'integer', 'gte:anchor.start'],
            'anchor.quote' => ['nullable', 'string', 'max:2000'],
            'anchor.channel' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function anchorType(): ?CommentAnchorType
    {
        $type = $this->input('anchor_type');

        return $type !== null ? CommentAnchorType::from((string) $type) : null;
    }

    /**
     * The anchor payload, narrowed to just the fields the chosen type uses (so no stray keys land
     * in the stored JSON).
     *
     * @return array<string, mixed>|null
     */
    public function anchorPayload(): ?array
    {
        return match ($this->anchorType()) {
            CommentAnchorType::Image => [
                'asset_id' => (int) $this->input('anchor.asset_id'),
                'x' => (float) $this->input('anchor.x'),
                'y' => (float) $this->input('anchor.y'),
            ],
            CommentAnchorType::Video => [
                'asset_id' => (int) $this->input('anchor.asset_id'),
                't' => (float) $this->input('anchor.t'),
            ],
            CommentAnchorType::Caption => [
                'channel' => $this->input('anchor.channel'),
                'start' => (int) $this->input('anchor.start'),
                'end' => (int) $this->input('anchor.end'),
                'quote' => (string) $this->input('anchor.quote', ''),
            ],
            null => null,
        };
    }
}
