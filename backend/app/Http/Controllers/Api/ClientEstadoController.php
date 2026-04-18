<?php

namespace App\Http\Controllers\Api;

use App\Models\ClientEstado;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class ClientEstadoController extends Controller
{
    /**
     * Listar todos los estados de cliente activos
     * GET /api/client-estados
     */
    public function index(): JsonResponse
    {
        $estados = ClientEstado::where('activo', true)
            ->orderBy('orden')
            ->get();

        return response()->json([
            'data' => $estados,
            'message' => 'Estados de cliente obtenidos correctamente',
        ]);
    }

    /**
     * Obtener un estado específico
     * GET /api/client-estados/{id}
     */
    public function show(ClientEstado $estado): JsonResponse
    {
        return response()->json([
            'data' => $estado,
            'message' => 'Estado de cliente obtenido correctamente',
        ]);
    }

    /**
     * Crear un nuevo estado de cliente
     * POST /api/client-estados
     * Solo admin
     */
    public function store(Request $request): JsonResponse
    {
        // Verificar que sea admin
        if (!$request->user()->isAdmin()) {
            return response()->json([
                'message' => 'No tienes permisos para crear estados de cliente',
            ], 403);
        }

        $validated = $request->validate([
            'nombre' => ['required', 'string', 'unique:cliente_estados,nombre', 'max:100'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'activo' => ['boolean'],
        ]);

        // Nuevo estado: asignar siempre el primer orden libre.
        $validated['orden'] = $this->firstFreeOrder();

        // Los nuevos estados NO son protegidos por el sistema
        $validated['sistema_protegido'] = false;

        $estado = ClientEstado::create($validated);

        return response()->json([
            'data' => $estado,
            'message' => 'Estado de cliente creado correctamente',
        ], 201);
    }

    /**
     * Actualizar un estado de cliente
     * PUT /api/client-estados/{id}
     * Solo admin, y no se pueden editar estados protegidos
     */
    public function update(Request $request, ClientEstado $estado): JsonResponse
    {
        // Verificar permisos
        if (!$request->user()->isAdmin()) {
            return response()->json([
                'message' => 'No tienes permisos para editar estados de cliente',
            ], 403);
        }

        // Bloquear edición de estados protegidos
        if ($estado->sistema_protegido) {
            return response()->json([
                'message' => 'Los estados del sistema no se pueden editar',
                'estado_id' => $estado->id,
            ], 422);
        }

        $validated = $request->validate([
            'nombre' => ['required', 'string', "unique:cliente_estados,nombre,{$estado->id}", 'max:100'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'orden' => ['integer', 'min:1'],
            'activo' => ['boolean'],
        ]);

        $estado->update($validated);

        return response()->json([
            'data' => $estado,
            'message' => 'Estado de cliente actualizado correctamente',
        ]);
    }

    /**
     * Eliminar un estado de cliente
     * DELETE /api/client-estados/{id}
     * Solo admin, no se pueden eliminar si está en uso o si es protegido
     */
    public function destroy(Request $request, ClientEstado $estado): JsonResponse
    {
        // Verificar permisos
        if (!$request->user()->isAdmin()) {
            return response()->json([
                'message' => 'No tienes permisos para eliminar estados de cliente',
            ], 403);
        }

        // Bloquear eliminación de estados protegidos
        if ($estado->sistema_protegido) {
            return response()->json([
                'message' => 'Los estados del sistema no se pueden eliminar',
                'estado_id' => $estado->id,
            ], 422);
        }

        // Bloquear eliminación si está en uso
        if ($estado->estaEnUso()) {
            return response()->json([
                'message' => 'No se puede eliminar un estado que está siendo utilizado en clientes',
                'estado_id' => $estado->id,
                'clientes_count' => $estado->clients()->count(),
            ], 422);
        }

        $estado->delete();

        return response()->json([
            'message' => 'Estado de cliente eliminado correctamente',
        ]);
    }

    private function firstFreeOrder(): int
    {
        $usedOrders = ClientEstado::query()
            ->pluck('orden')
            ->filter(fn ($value) => is_numeric($value) && (int) $value > 0)
            ->map(fn ($value) => (int) $value)
            ->sort()
            ->values();

        $next = 1;
        foreach ($usedOrders as $order) {
            if ($order === $next) {
                $next++;
                continue;
            }

            if ($order > $next) {
                break;
            }
        }

        return $next;
    }
}
