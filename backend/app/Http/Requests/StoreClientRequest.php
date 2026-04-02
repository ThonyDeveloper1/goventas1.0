<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate already handled by middleware
    }

    public function rules(): array
    {
        return [
            'dni'           => ['required', 'string', 'size:8', 'regex:/^\d{8}$/', 'unique:clients,dni'],
            'nombres'       => ['required', 'string', 'max:100'],
            'apellidos'     => ['required', 'string', 'max:100'],
            'telefono_1'    => ['required', 'string', 'max:15'],
            'telefono_2'    => ['nullable', 'string', 'max:15'],
            'direccion'     => ['required', 'string', 'max:255'],
            'referencia'    => ['nullable', 'string', 'max:255'],
            'departamento'  => ['required', 'string', 'max:100'],
            'provincia'     => ['required', 'string', 'max:100'],
            'distrito'      => ['required', 'string', 'max:100'],
            'latitud'       => ['nullable', 'numeric', 'between:-90,90'],
            'longitud'      => ['nullable', 'numeric', 'between:-180,180'],
            // 'estado' is controlled by MikroTik sync — not editable via API
            'plan_id'          => ['nullable', 'integer', 'exists:plans,id'],
            'target_user_id'   => ['nullable', 'integer', 'exists:users,id'],
            'fecha_registro'   => ['nullable', 'date'],
            'installacion_fecha' => array_filter([
                'nullable',
                'date_format:Y-m-d',
                $this->user()?->isAdmin() ? null : 'after_or_equal:today',
            ]),
            'installacion_hora_inicio' => ['nullable', 'date_format:H:i'],
            'installacion_duracion' => ['nullable', 'integer', 'in:1,2'],
            'fotos'         => ['nullable', 'array', 'max:5'],
            'fotos.*'       => ['image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
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

            $totalPhotos = count($this->file('fotos', []))
                + count($this->file('fotos_fachada', []))
                + count($this->file('fotos_dni', []));

            if ($totalPhotos < 1) {
                $validator->errors()->add('fotos', 'Debes subir al menos una foto de evidencia.');
            }

            if ($totalPhotos > 5) {
                $validator->errors()->add('fotos', 'Solo se permiten máximo 5 fotos en total.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'dni.size'    => 'El DNI debe tener exactamente 8 dígitos.',
            'dni.regex'   => 'El DNI solo puede contener números.',
            'dni.unique'  => 'Este DNI ya está registrado.',
            'direccion.required' => 'La dirección es obligatoria.',
            'distrito.required'  => 'El distrito es obligatorio.',
            'fotos.required' => 'Debes subir al menos una foto de evidencia.',
            'fotos.max' => 'Solo se permiten máximo 5 fotos.',
            'fotos_fachada.max' => 'Solo se permiten máximo 5 fotos de fachada.',
            'fotos_dni.max' => 'Solo se permiten máximo 5 fotos de DNI.',
            'fotos.*.max' => 'Cada foto no puede superar los 4 MB.',
            'fotos_fachada.*.max' => 'Cada foto no puede superar los 4 MB.',
            'fotos_dni.*.max' => 'Cada foto no puede superar los 4 MB.',
        ];
    }
}
