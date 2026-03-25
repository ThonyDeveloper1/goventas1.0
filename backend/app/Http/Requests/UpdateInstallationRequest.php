<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInstallationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id'   => ['sometimes', 'integer', 'exists:clients,id'],
            'fecha'       => ['sometimes', 'date_format:Y-m-d'],
            'hora_inicio' => ['sometimes', 'date_format:H:i'],
            'duracion'    => ['nullable', 'integer', 'in:1,2'],
            'estado'      => ['sometimes', 'in:pendiente,en_proceso,completado'],
            'notas'       => ['nullable', 'string', 'max:500'],
        ];
    }
}
