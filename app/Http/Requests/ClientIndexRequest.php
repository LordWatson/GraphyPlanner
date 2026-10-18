<?php

namespace App\Http\Requests;

use App\Enums\ClientHealth;
use App\Enums\ClientStatus;
use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step I-2.5 — the clients roster's query string: search, filters, sort and page size.
 */
class ClientIndexRequest extends FormRequest
{
    public const SORTS = ['name', 'health', 'owner', 'start_date', 'retainer'];

    public const PER_PAGE = [25, 50, 100];

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Client::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'archived' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ClientStatus::class)],
            'health' => ['nullable', Rule::enum(ClientHealth::class)],
            'owner' => ['nullable', 'integer'],
            'tag' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{archived: bool, search: string|null, status: string|null, health: string|null, owner: int|null, tag: string|null, sort: string, direction: string, per_page: int}
     */
    public function filters(): array
    {
        return [
            'archived' => $this->boolean('archived'),
            'search' => $this->string('search')->trim()->toString() ?: null,
            'status' => $this->string('status')->toString() ?: null,
            'health' => $this->string('health')->toString() ?: null,
            'owner' => $this->integer('owner') ?: null,
            'tag' => $this->string('tag')->trim()->toString() ?: null,
            'sort' => $this->string('sort')->toString() ?: 'name',
            'direction' => $this->string('direction')->toString() ?: 'asc',
            'per_page' => $this->integer('per_page') ?: self::PER_PAGE[0],
        ];
    }
}
