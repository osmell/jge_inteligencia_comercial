<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Menu\MenuCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Opciones del menú que se le ocultan a una persona (lista vacía: ve todo lo que su rol permite). */
class MenuAccessRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hidden' => ['present', 'array', 'max:'.count(MenuCatalog::OPTIONS)],
            'hidden.*' => ['required', 'string', 'distinct', Rule::in(MenuCatalog::keys())],
        ];
    }

    /** @return list<string> en el orden del menú */
    public function hidden(): array
    {
        $chosen = (array) $this->validated('hidden');

        return array_values(array_filter(MenuCatalog::keys(), fn (string $key) => in_array($key, $chosen, true)));
    }
}
