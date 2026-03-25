<?php

namespace App\Http\Controllers;

use App\Services\PagosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PagoController extends Controller
{
    public function __construct(private readonly PagosService $pagosService) {}

    /* ─── GET /pagos ──────────────────────────────────── */
    public function index(Request $request): JsonResponse
    {
        $clientId = $request->input('client_id');
        $perPage  = $request->input('per_page', 15);

        $pagos = $this->pagosService->findAll($clientId, $perPage);

        return response()->json($pagos);
    }

    /* ─── POST /pagos ─────────────────────────────────── */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id'     => ['required', 'exists:clients,id'],
            'monto'         => ['required', 'numeric', 'min:0.01'],
            'fecha_pago'    => ['sometimes', 'date'],
            'metodo_pago'   => ['required', 'string', 'in:efectivo,yape,plin,transferencia,deposito'],
            'comprobante'   => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $pago = $this->pagosService->registrarPago($validated, $request->user()->id);

            return response()->json([
                'success' => true,
                'message' => 'Pago registrado correctamente.',
                'data'    => $pago,
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /* ─── GET /pagos/{id} ─────────────────────────────── */
    public function show(int $id): JsonResponse
    {
        try {
            $pago = $this->pagosService->findOne($id);
            return response()->json($pago);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Pago no encontrado.',
            ], 404);
        }
    }
}
