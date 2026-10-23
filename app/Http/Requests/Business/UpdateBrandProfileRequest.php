<?php

namespace App\Http\Requests\Business;

use App\Policies\ClientPolicy;
use App\Services\Workspace\CurrentWorkspace;
use App\Support\Workspace\PostingGoal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step B-4.1 / B-4.5 — the business "/brand" page's basics: the brand's name, what it does
 * (`industry`), location, website, and its posting goal (how many posts a week, which days). The
 * brand is implicit — resolved from {@see CurrentWorkspace}, never a `{client}` route parameter.
 */
class UpdateBrandProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $brand = app(CurrentWorkspace::class)->brand();

        return $brand !== null && app(ClientPolicy::class)->update($this->user(), $brand);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'one_liner' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'string', 'max:255'],

            // Step B-4.5: the posting goal. A whole, sane range so a stray value can't skew the
            // "Gaps" computation; the days are ISO-8601 weekday numbers (1 = Mon … 7 = Sun).
            'posting_goal' => ['nullable', 'array'],
            'posting_goal.posts_per_week' => ['nullable', 'integer', 'min:0', 'max:21'],
            'posting_goal.preferred_days' => ['nullable', 'array', 'max:7'],
            'posting_goal.preferred_days.*' => ['integer', 'min:1', 'max:7'],
        ];
    }

    /**
     * Mapped onto `clients` columns ("what you do" → `industry`); the posting goal is normalised
     * through its value object so only a clean shape is stored (null when the brand clears it).
     *
     * @return array<string, mixed>
     */
    public function brandData(): array
    {
        $goal = PostingGoal::fromArray($this->array('posting_goal'));

        return [
            'name' => $this->string('name')->trim()->toString(),
            'industry' => $this->filled('one_liner') ? $this->string('one_liner')->trim()->toString() : null,
            'location' => $this->filled('location') ? $this->string('location')->trim()->toString() : null,
            'website' => $this->filled('website') ? $this->string('website')->trim()->toString() : null,
            'posting_goal' => $goal->isSet() ? $goal->toArray() : null,
        ];
    }
}
