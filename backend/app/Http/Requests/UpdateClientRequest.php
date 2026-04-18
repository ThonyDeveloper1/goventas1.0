<?php

namespace App\Http\Requests;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $clientId = $this->route('client')?->id ?? $this->route('client');

        return [
            'dni'          => ['sometimes', 'string', 'size:8', 'regex:/^\d{8}$/', Rule::unique('clients', 'dni')->ignore($clientId)],
            'nombres'      => ['sometimes', 'string', 'max:100'],
            'apellidos'    => ['sometimes', 'string', 'max:100'],
            'telefono_1'   => ['sometimes', 'string', 'max:15'],
            'telefono_2'   => ['nullable', 'string', 'max:15'],
            'direccion'    => ['sometimes', 'string', 'max:255'],
            'referencia'   => ['nullable', 'string', 'max:255'],
            'departamento' => ['sometimes', 'string', 'max:100'],
            'provincia'    => ['sometimes', 'string', 'max:100'],
            'distrito'     => ['sometimes', 'string', 'max:100'],
            'latitud'      => ['nullable', 'numeric', 'between:-90,90'],
            'longitud'     => ['nullable', 'numeric', 'between:-180,180'],
            // 'estado' is controlled by MikroTik sync — not editable via API
            'plan_id'      => ['nullable', 'integer', 'exists:plans,id'],
            'installacion_fecha' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'installacion_hora_inicio' => ['nullable', 'date_format:H:i'],
            'installacion_duracion' => ['nullable', 'integer', 'in:1'],
            'fotos'        => ['nullable', 'array', 'max:5'],
            'fotos.*'      => ['image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
            'fotos_fachada' => ['nullable', 'array', 'max:5'],
            'fotos_fachada.*' => ['image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
            'fotos_dni'     => ['nullable', 'array', 'max:5'],
            'fotos_dni.*'   => ['image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasInstallDate = $this->filled('installacion_fecha');
            $hasInstallHour = $this->filled('installacion_hora_inicio');
            $hasInstallDuration = $this->filled('installacion_duracion');

            if ($hasInstallDate || $hasInstallHour || $hasInstallDuration) {
                if (! $hasInstallDate) {
                    $validator->errors()->add('installacion_fecha', 'Debes indicar la fecha de instalación.');
                }
                if (! $hasInstallHour) {
                    $validator->errors()->add('installacion_hora_inicio', 'Debes indicar la hora de inicio de instalación.');
                }
                if (! $hasInstallDuration) {
                    $validator->errors()->add('installacion_duracion', 'Debes indicar la duración de instalación.');
                }
            }

            $existingPhotos = $this->route('client') instanceof Client
                ? $this->route('client')->photos()->count()
                : 0;

            $totalPhotos = $existingPhotos
                + count($this->file('fotos', []))
                + count($this->file('fotos_fachada', []))
                + count($this->file('fotos_dni', []));

            if ($totalPhotos < 1) {
                $validator->errors()->add('fotos', 'Debes mantener al menos una foto de evidencia.');
            }

            if ($totalPhotos > 5) {
                $validator->errors()->add('fotos', 'Solo se permiten máximo 5 fotos en total.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'fotos.max' => 'Solo se permiten máximo 5 fotos.',
            'fotos_fachada.max' => 'Solo se permiten máximo 5 fotos de fachada.',
            'fotos_dni.max' => 'Solo se permiten máximo 5 fotos de DNI.',
            'fotos.*.max' => 'Cada foto no puede superar los 4 MB.',
            'fotos_fachada.*.max' => 'Cada foto no puede superar los 4 MB.',
            'fotos_dni.*.max' => 'Cada foto no puede superar los 4 MB.',
        ];
    }
}
