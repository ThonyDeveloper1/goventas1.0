<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PDFController extends Controller
{
    /* ─────────────────────────────────────────────────────────
     |  GET /reports/clients/pdf
     |  Export filtered client list as PDF.
     ────────────────────────────────────────────────────────── */
    public function exportClientsPDF(Request $request): Response
    {
        $query = Client::select(
                'id', 'dni', 'nombres', 'apellidos', 'telefono_1',
                'direccion', 'distrito', 'estado', 'mikrotik_profile',
                'user_id', 'created_at'
            )
            ->with('vendedora:id,name');

        if ($vendorId = $request->input('vendor_id')) {
            $query->where('user_id', $vendorId);
        }

        if ($estado = $request->input('estado')) {
            $query->where('estado', $estado);
        }

        if ($search = $request->input('search')) {
            $query->search($search);
        }

        $clients = $query->orderBy('apellidos')->get();
        $clients->each(fn ($c) => $c->append('nombre_completo'));

        $vendorName = 'Todos';
        if ($vendorId) {
            $vendor = $clients->first()?->vendedora;
            $vendorName = $vendor?->name ?? 'Vendedora #' . $vendorId;
        }

        $pdf = Pdf::loadView('pdf.clients', [
            'clients'    => $clients,
            'vendorName' => $vendorName,
            'estado'     => $estado ?: 'Todos',
            'date'       => now()->format('d/m/Y H:i'),
            'total'      => $clients->count(),
        ]);

        $pdf->setPaper('a4', 'landscape');

        $filename = 'clientes_' . now()->format('Ymd_His') . '.pdf';

        return $pdf->download($filename);
    }
}
