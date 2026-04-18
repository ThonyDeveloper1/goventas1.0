<?php

namespace Database\Seeders;

use App\Models\ClientEstado;
use Illuminate\Database\Seeder;

class ClientEstadoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Los 5 estados iniciales del sistema, marcados como protegidos
        $estadosIniciales = [
            [
                'nombre' => 'pre_registro',
                'color' => '#9CA3AF',
                'descripcion' => 'Cliente registrado pero sin instalación planificada',
                'orden' => 1,
                'activo' => true,
                'sistema_protegido' => true,
            ],
            [
                'nombre' => 'en_proceso',
                'color' => '#3B82F6',
                'descripcion' => 'Instalación en proceso',
                'orden' => 2,
                'activo' => true,
                'sistema_protegido' => true,
            ],
            [
                'nombre' => 'finalizada',
                'color' => '#10B981',
                'descripcion' => 'Instalación completada',
                'orden' => 3,
                'activo' => true,
                'sistema_protegido' => true,
            ],
            [
                'nombre' => 'suspendido',
                'color' => '#F59E0B',
                'descripcion' => 'Cliente suspendido temporalmente',
                'orden' => 4,
                'activo' => true,
                'sistema_protegido' => true,
            ],
            [
                'nombre' => 'baja',
                'color' => '#EF4444',
                'descripcion' => 'Cliente dado de baja',
                'orden' => 5,
                'activo' => true,
                'sistema_protegido' => true,
            ],
        ];

        foreach ($estadosIniciales as $estado) {
            // Usar updateOrCreate para evitar duplicados si se ejecuta múltiples veces
            ClientEstado::updateOrCreate(
                ['nombre' => $estado['nombre']],
                $estado
            );
        }
    }
}
