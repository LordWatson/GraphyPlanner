<?php

namespace App\Http\Requests\Business;

use App\Enums\DealStatus;
use App\Enums\Platform;
use App\Http\Controllers\Business\BrandDealController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step CG-5.2 — validating a brand deal. The fee is submitted in minor units (the page never sends
 * a float), and a deliverable can only name a platform we actually publish to.
 *
 * Authorisation is the controller's ({@see BrandDealController}),
 * which checks the brand-update ability first.
 */
class SaveBrandDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'partner_name' => ['required', 'string', 'max:120'],
            'partner_handle' => ['nullable', 'string', 'max:64'],
            'status' => ['required', Rule::enum(DealStatus::class)],
            'fee_minor' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'starts_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'show_on_media_kit' => ['boolean'],

            'deliverables' => ['nullable', 'array', 'max:20'],
            'deliverables.*.platform' => ['required', Rule::enum(Platform::class)],
            'deliverables.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
