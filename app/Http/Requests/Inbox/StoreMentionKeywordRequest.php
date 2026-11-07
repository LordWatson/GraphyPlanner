<?php

namespace App\Http\Requests\Inbox;

use App\Models\MentionKeyword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMentionKeywordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', MentionKeyword::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'term' => [
                'required',
                'string',
                'min:2',
                'max:80',
                // Scoped to the organisation, so two agencies can watch the same word. The unique
                // index on (org_id, term) is the backstop; this is the readable error.
                Rule::unique(MentionKeyword::class, 'term')->where('org_id', $this->user()->org_id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'term.unique' => __('You are already watching that word.'),
            'term.min' => __('A one-letter word would match almost every comment.'),
            'full' => __('You are watching the maximum of :max words. Remove one first.', ['max' => MentionKeyword::MAX_PER_ORG]),
        ];
    }

    /**
     * Every term is a regex run against every new comment, so the list has a ceiling. Checked here
     * rather than silently ignoring the ones past the limit, which is how a feature starts lying.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $count = MentionKeyword::query()->where('org_id', $this->user()->org_id)->count();

                if ($count >= MentionKeyword::MAX_PER_ORG) {
                    $validator->errors()->add('term', $this->messages()['full']);
                }
            },
        ];
    }
}
