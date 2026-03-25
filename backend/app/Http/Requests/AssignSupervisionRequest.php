<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignSupervisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'installation_id' => [
                'required',
                'integer',
                'exists:installations,id',
                'unique:supervisions,installation_id',
            ],
            'supervisor_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'installation_id.unique' => 'Esta instalación ya tiene una supervisión asignada.',
            'installation_id.exists' => 'La instalación no existe.',
            'supervisor_id.exists'   => 'El supervisor seleccionado no existe.',
        ];
    }
}
