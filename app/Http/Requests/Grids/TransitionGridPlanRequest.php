<?php

namespace App\Http\Requests\Grids;

use App\Actions\Grids\GridPlanStatusTransitionMap;
use App\Enums\GridPlanStatus;
use App\Http\Requests\Grids\Concerns\GridRules;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Step 18.7 — move a grid plan (mirrors `TransitionPostRequest`). The checklist gate itself runs
 * inside `TransitionGridPlanStatusAction`, against the locked plan.
 */
class TransitionGridPlanRequest extends FormRequest
{
    use GridRules;

    public function authorize(): bool
    {
        $to = GridPlanStatus::tryFrom((string) $this->input('to'));

        if ($to === null) {
            return $this->user()->can('view', $this->plan());
        }

        return $this->user()->can('transition', [$this->plan(), $to]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to' => [
                'required',
                new Enum(GridPlanStatus::class),
                function (string $attribute, mixed $value, Closure $fail) {
                    $plan = $this->plan();
                    $to = GridPlanStatus::tryFrom((string) $value);

                    if ($to !== null && GridPlanStatusTransitionMap::needsClientApproval($plan, $plan->status, $to)) {
                        $fail(__('This grid needs the client’s approval. Send it to the client, or an Owner can override.'));

                        return;
                    }

                    if ($to !== null && ! GridPlanStatusTransitionMap::isAllowedFor($plan, $to)) {
                        $fail(__('This grid can’t move from :from to :to.', ['from' => strtolower($plan->status->label()), 'to' => strtolower($to->label())]));
                    }
                },
            ],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
