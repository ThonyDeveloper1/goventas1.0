<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInstallationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id'   => ['required', 'integer', 'exists:clients,id'],
            'user_id'     => ['nullable', 'integer', 'exists:users,id'],
            'fecha'       => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'duracion'    => ['required', 'integer', 'in:1,2'],
            'estado'      => ['sometimes', 'in:pendiente,en_proceso,completado'],
            'notas'       => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'fecha.after_or_equal' => 'No se puede agendar en una fecha pasada.',
            'client_id.exists'     => 'El cliente seleccionado no existe.',
        ];
    }
}
