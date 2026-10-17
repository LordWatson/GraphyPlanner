<?php

namespace App\Http\Requests\SuperAdmin;

use App\Actions\SuperAdmin\ListOrganizationsAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(ListOrganizationsAction::STATUSES)],
            'sort' => ['nullable', Rule::in(ListOrganizationsAction::SORTS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
