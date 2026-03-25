<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Clientes — GO Systems</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 10px; color: #333; }

        .header { padding: 20px 30px; border-bottom: 3px solid #ff0080; margin-bottom: 15px; }
        .header h1 { font-size: 18px; color: #ff0080; margin-bottom: 2px; }
        .header p { font-size: 10px; color: #666; }

        .meta { padding: 0 30px; margin-bottom: 15px; display: table; width: 100%; }
        .meta-item { display: table-cell; }
        .meta-item span { font-weight: bold; color: #333; }

        table { width: calc(100% - 60px); margin: 0 30px; border-collapse: collapse; }
        thead th {
            background: #f8f8f8;
            border-bottom: 2px solid #ff0080;
            padding: 8px 6px;
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #555;
        }
        tbody td {
            padding: 6px;
            border-bottom: 1px solid #eee;
            font-size: 9px;
        }
        tbody tr:nth-child(even) { background: #fafafa; }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-activo      { background: #d1fae5; color: #065f46; }
        .badge-moroso       { background: #fef3c7; color: #92400e; }
        .badge-suspendido   { background: #fee2e2; color: #991b1b; }
        .badge-baja         { background: #f3f4f6; color: #374151; }

        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 10px 30px;
            border-top: 1px solid #eee;
            font-size: 8px;
            color: #999;
            text-align: center;
        }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
    <div class="header">
        <h1>GO Systems & Technology</h1>
        <p>Reporte de Clientes — Generado: {{ $date }}</p>
    </div>

    <div class="meta">
        <div class="meta-item">Vendedora: <span>{{ $vendorName }}</span></div>
        <div class="meta-item">Estado: <span>{{ $estado }}</span></div>
        <div class="meta-item">Total: <span>{{ $total }} cliente(s)</span></div>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>DNI</th>
                <th>Nombre Completo</th>
                <th>Teléfono</th>
                <th>Dirección</th>
                <th>Distrito</th>
                <th>Estado</th>
                <th>Plan</th>
                <th>Registro</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($clients as $index => $client)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $client->dni }}</td>
                    <td>{{ $client->nombre_completo }}</td>
                    <td>{{ $client->telefono_1 }}</td>
                    <td>{{ $client->direccion }}</td>
                    <td>{{ $client->distrito }}</td>
                    <td>
                        <span class="badge badge-{{ $client->estado }}">
                            {{ $client->estado }}
                        </span>
                    </td>
                    <td>{{ $client->mikrotik_profile ?? '—' }}</td>
                    <td>{{ $client->created_at?->format('d/m/Y') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" style="text-align: center; padding: 20px; color: #999;">
                        No se encontraron clientes con los filtros seleccionados.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        GO Systems & Technology — {{ $date }} — Página <span class="pagenum"></span>
    </div>
</body>
</html>
