<?php

namespace App\Http\Requests\Business;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Http\Controllers\Business\ProductController;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Step CG-4.2 — validating a product the creator is about to put on sale. Money is submitted in
 * minor units (the page never sends a float), and the per-type payload is validated per type so a
 * booking can't ship without availability or a download without a file.
 *
 * Authorisation is the controller's ({@see ProductController}), which
 * checks the brand-update ability before this runs.
 */
class SaveProductRequest extends FormRequest
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
            'type' => ['required', Rule::enum(ProductType::class)],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_minor' => ['required', 'integer', 'min:0', 'max:5000000'],
            'currency' => ['required', 'string', Rule::in(config('payments.currencies'))],
            'status' => ['required', Rule::enum(ProductStatus::class)],
            'cover_asset_id' => ['nullable', 'integer'],
            'download_asset_id' => ['nullable', 'integer'],

            'settings' => ['nullable', 'array'],
            'settings.download_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'settings.download_expiry_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'settings.min_minor' => ['nullable', 'integer', 'min:100', 'max:5000000'],
            // Capped at six days: a card authorisation lapses after about seven, and we must cancel
            // or capture before it does.
            'settings.answer_window_hours' => ['nullable', 'integer', 'min:1', 'max:144'],
            'settings.availability' => ['nullable', 'array'],
            'settings.availability.weekdays' => ['nullable', 'array', 'min:1'],
            'settings.availability.weekdays.*' => ['integer', 'min:1', 'max:7'],
            'settings.availability.start' => ['nullable', 'date_format:H:i'],
            'settings.availability.end' => ['nullable', 'date_format:H:i', 'after:settings.availability.start'],
            'settings.availability.slot_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'settings.availability.buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'settings.availability.lead_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'settings.availability.days_ahead' => ['nullable', 'integer', 'min:1', 'max:120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = ProductType::tryFrom((string) $this->input('type'));
            $status = ProductStatus::tryFrom((string) $this->input('status'));
            $price = (int) $this->input('price_minor');

            if ($type === null || $status !== ProductStatus::Live) {
                return;
            }

            // Only what would actually break a live sale is enforced; a draft can be half-finished.
            if ($type === ProductType::Download && $this->input('download_asset_id') === null) {
                $validator->errors()->add('download_asset_id', __('Pick the file buyers will get.'));
            }

            if (! $type->buyerSetsAmount() && $price < Product::MIN_CHARGE_MINOR) {
                $validator->errors()->add('price_minor', __('A card payment needs to be at least 1.00.'));
            }
        });
    }
}
