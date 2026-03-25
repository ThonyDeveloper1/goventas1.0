<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Plan::query();

        if ($request->has('activo')) {
            $query->where('activo', $request->boolean('activo'));
        }

        if ($search = $request->input('search')) {
            $query->where('nombre', 'ilike', "%{$search}%");
        }

        $plans = $query->withCount('clients')
            ->orderBy('precio')
            ->get();

        return response()->json($plans);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre'           => ['required', 'string', 'max:100'],
            'velocidad_bajada' => ['required', 'integer', 'min:1'],
            'velocidad_subida' => ['required', 'integer', 'min:1'],
            'precio'           => ['required', 'numeric', 'min:0'],
            'condiciones'      => ['nullable', 'string', 'max:500'],
            'activo'           => ['nullable', 'boolean'],
        ]);

        $plan = Plan::create($validated);

        return response()->json([
            'message' => 'Plan creado correctamente.',
            'data'    => $plan->loadCount('clients'),
        ], 201);
    }

    public function show(Plan $plan): JsonResponse
    {
        return response()->json($plan->loadCount('clients'));
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $validated = $request->validate([
            'nombre'           => ['sometimes', 'string', 'max:100'],
            'velocidad_bajada' => ['sometimes', 'integer', 'min:1'],
            'velocidad_subida' => ['sometimes', 'integer', 'min:1'],
            'precio'           => ['sometimes', 'numeric', 'min:0'],
            'condiciones'      => ['nullable', 'string', 'max:500'],
            'activo'           => ['nullable', 'boolean'],
        ]);

        $plan->update($validated);

        return response()->json([
            'message' => 'Plan actualizado correctamente.',
            'data'    => $plan->fresh()->loadCount('clients'),
        ]);
    }

    public function destroy(Plan $plan): JsonResponse
    {
        if ($plan->clients()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar un plan con clientes asignados. Desactívalo en su lugar.',
            ], 422);
        }

        $plan->delete();

        return response()->json(['message' => 'Plan eliminado correctamente.']);
    }
}
