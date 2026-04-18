<?php

namespace App\Http\Controllers;

use App\Models\SupervisionEstado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupervisionEstadoController extends Controller
{
    /* ─────────────────────────────────────────────────────────
     |  GET /supervisions/estados
     |  Admin only
     ────────────────────────────────────────────────────────── */
    public function index(): JsonResponse
    {
        $estados = SupervisionEstado::orderBy('orden')->orderBy('id')->get();

        return response()->json($estados);
    }

    /* ─────────────────────────────────────────────────────────
     |  POST /supervisions/estados
     ────────────────────────────────────────────────────────── */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre'      => ['required', 'string', 'max:100'],
            'color'       => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'orden'       => ['nullable', 'integer', 'min:0'],
            'activo'      => ['nullable', 'boolean'],
        ]);

        $estado = SupervisionEstado::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Estado creado correctamente.',
            'data'    => $estado,
        ], 201);
    }

    /* ─────────────────────────────────────────────────────────
     |  PUT /supervisions/estados/{id}
     ────────────────────────────────────────────────────────── */
    public function update(Request $request, int $id): JsonResponse
    {
        $estado = SupervisionEstado::findOrFail($id);

        $data = $request->validate([
            'nombre'      => ['sometimes', 'required', 'string', 'max:100'],
            'color'       => ['sometimes', 'required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'orden'       => ['nullable', 'integer', 'min:0'],
            'activo'      => ['nullable', 'boolean'],
        ]);

        $estado->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Estado actualizado correctamente.',
            'data'    => $estado->fresh(),
        ]);
    }

    /* ─────────────────────────────────────────────────────────
     |  DELETE /supervisions/estados/{id}
     ────────────────────────────────────────────────────────── */
    public function destroy(int $id): JsonResponse
    {
        $estado = SupervisionEstado::findOrFail($id);

        if ($estado->supervisions()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar: hay supervisiones usando este estado.',
            ], 422);
        }

        $estado->delete();

        return response()->json([
            'success' => true,
            'message' => 'Estado eliminado.',
        ]);
    }
}
