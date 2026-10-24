<?php

namespace App\Http\Requests\Inbox;

use App\Models\Client;
use App\Models\SavedReply;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step M-9.2 — create/update a saved reply. `client_id`, when given, must be a client of the
 * actor's own organization (the org scope on the lookup enforces that). Authorized here (before
 * validation) so a cross-tenant update is refused 403 rather than tripping validation first.
 */
class SaveSavedReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reply = $this->route('savedReply');

        if ($reply instanceof SavedReply) {
            return $this->user()?->can('update', $reply) ?? false;
        }

        return $this->user()?->can('create', SavedReply::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:5000'],
            'shortcut' => ['nullable', 'string', 'max:64'],
            'client_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== null && Client::query()->whereKey($value)->doesntExist()) {
                        $fail('Choose a client in your workspace.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array{title: string, body: string, shortcut: string|null, client_id: int|null}
     */
    public function payload(): array
    {
        return [
            'title' => $this->string('title')->trim()->toString(),
            'body' => $this->string('body')->trim()->toString(),
            'shortcut' => $this->string('shortcut')->trim()->toString() ?: null,
            'client_id' => $this->integer('client_id') ?: null,
        ];
    }
}
