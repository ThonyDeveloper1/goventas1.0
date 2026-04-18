<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignSupervisionRequest;
use App\Models\InternalNotification;
use App\Models\Installation;
use App\Models\Supervision;
use App\Models\SupervisionEstado;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SupervisionController extends Controller
{
    /* ─────────────────────────────────────────────────────────
     |  GET /supervisions
     ────────────────────────────────────────────────────────── */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Supervision::query()
            ->with([
                'installation.client:id,nombres,apellidos,dni,distrito,latitud,longitud',
                'installation.vendedora:id,name',
                'supervisor:id,name',
                'photos',
                'estadoSupervision',
            ])
            ->forUser($user);

        if ($estado = $request->input('estado')) {
            $query->estado($estado);
        }

        if ($supervisorId = $request->input('supervisor_id')) {
            if ($user->isAdmin()) {
                $query->where('supervisor_id', $supervisorId);
            }
        }

        if ($installationId = $request->input('installation_id')) {
            $query->where('installation_id', $installationId);
        }

        $supervisions = $query
            ->latest()
            ->paginate($request->input('per_page', 15));

        $supervisions->getCollection()->transform(function ($s) {
            $s->photos->each(fn ($p) => $p->append('url'));
            return $s;
        });

        return response()->json($supervisions);
    }

    /* ─────────────────────────────────────────────────────────
     |  GET /supervisions/{id}
     ────────────────────────────────────────────────────────── */
    public function show(Request $request, int $id): JsonResponse
    {
        $supervision = Supervision::with([
            'installation.client:id,nombres,apellidos,dni,telefono_1,direccion,distrito,latitud,longitud,ip_address,service_status',
            'installation.client.photos',
            'installation.vendedora:id,name',
            'supervisor:id,name',
            'photos',
            'estadoSupervision',
        ])->findOrFail($id);

        $this->authorizeAccess($request->user(), $supervision);

        $supervision->photos->each(fn ($p) => $p->append('url'));
        if ($supervision->installation->client->photos) {
            $supervision->installation->client->photos->each(fn ($p) => $p->append('url'));
        }

        return response()->json($supervision);
    }

    /* ─────────────────────────────────────────────────────────
     |  POST /supervisions/assign
     |  Admin only
     ────────────────────────────────────────────────────────── */
    public function assign(AssignSupervisionRequest $request): JsonResponse
    {
        $supervisor = User::findOrFail($request->supervisor_id);

        if (! $supervisor->isSupervisor()) {
            throw ValidationException::withMessages([
                'supervisor_id' => ['El usuario seleccionado no tiene rol de supervisor.'],
            ]);
        }

        $supervision = DB::transaction(function () use ($request, $supervisor) {
            $primerEstado = SupervisionEstado::where('activo', true)
                ->orderBy('orden')
                ->orderBy('id')
                ->first();

            $supervision = Supervision::create([
                'installation_id' => $request->installation_id,
                'supervisor_id'   => $supervisor->id,
                'estado'          => 'pendiente',
                'estado_id'       => $primerEstado?->id,
            ]);

            // Notificar al supervisor
            $installation = $supervision->installation()->with('client:id,nombres,apellidos')->first();
            InternalNotification::create([
                'user_id' => $supervisor->id,
                'tipo'    => 'supervision_asignada',
                'titulo'  => 'Nueva supervisión asignada',
                'mensaje' => "Se te asignó la supervisión de la instalación para {$installation->client->nombres} {$installation->client->apellidos}.",
                'data'    => [
                    'supervision_id'  => $supervision->id,
                    'installation_id' => $supervision->installation_id,
                ],
            ]);

            return $supervision;
        });

        $supervision->load([
            'installation.client:id,nombres,apellidos,dni,distrito',
            'supervisor:id,name',
            'photos',
            'estadoSupervision',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Supervisión asignada correctamente.',
            'data'    => $supervision,
        ], 201);
    }

    /* ─────────────────────────────────────────────────────────
     |  PATCH /supervisions/{id}
     |  Admin or assigned supervisor — update checklist fields
     ────────────────────────────────────────────────────────── */
    public function update(Request $request, int $id): JsonResponse
    {
        $supervision = Supervision::findOrFail($id);
        $this->authorizeAssigned($request->user(), $supervision);

        $data = $request->validate([
            'comentario'             => ['nullable', 'string', 'max:1000'],
            'notas_supervisor'       => ['nullable', 'string', 'max:2000'],
            'fachada_verificada'     => ['nullable', 'boolean'],
            'conexiones_verificadas' => ['nullable', 'boolean'],
            'ubicacion_confirmada'   => ['nullable', 'boolean'],
            'servicio_verificado'    => ['nullable', 'boolean'],
            'nivel_senal'            => ['nullable', 'string', 'max:50'],
        ]);

        $supervision->update(array_filter($data, fn ($v) => $v !== null || is_bool($v)));

        $supervision->load('estadoSupervision');

        return response()->json([
            'success' => true,
            'message' => 'Supervisión actualizada.',
            'data'    => $supervision,
        ]);
    }

    /* ─────────────────────────────────────────────────────────
     |  POST /supervisions/{id}/estado
     |  Admin or assigned supervisor — set any estado
     ────────────────────────────────────────────────────────── */
    public function setState(Request $request, int $id): JsonResponse
    {
        $supervision = Supervision::findOrFail($id);
        $this->authorizeAssigned($request->user(), $supervision);

        $request->validate([
            'estado_id'  => ['required', 'integer', 'exists:supervision_estados,id'],
            'comentario' => ['nullable', 'string', 'max:1000'],
        ]);

        $estado = SupervisionEstado::findOrFail($request->estado_id);

        $supervision->update([
            'estado_id'  => $estado->id,
            'comentario' => $request->input('comentario', $supervision->comentario),
        ]);

        $supervision->load('estadoSupervision');

        return response()->json([
            'success' => true,
            'message' => 'Estado actualizado: ' . $estado->nombre . '.',
            'data'    => $supervision,
        ]);
    }

    /* ─────────────────────────────────────────────────────────
     |  GET /supervisions/tickets
     |  Admin + Supervisor — all installations with supervision data
     ────────────────────────────────────────────────────────── */
    public function tickets(Request $request): JsonResponse
    {
        $user = $request->user();

        $mes      = $request->input('mes');       // e.g. '2026-04'
        $estadoId = $request->input('estado_id'); // filter by estado
        $history  = $request->boolean('history'); // include Aprobado

        $query = Installation::query()
            ->with([
                'client:id,nombres,apellidos,dni,telefono_1,distrito,ip_address,service_status',
                'vendedora:id,name',
                'supervision.estadoSupervision',
                'supervision.supervisor:id,name',
            ])
            ->select('installations.*');

        // Supervisor role: only installations they are assigned to supervise
        if ($user->isSupervisor()) {
            $query->whereHas('supervision', fn ($q) => $q->where('supervisor_id', $user->id));
        }

        // Filter by month of installation date
        if ($mes && preg_match('/^\d{4}-\d{2}$/', $mes)) {
            $query->whereRaw("to_char(installations.fecha, 'YYYY-MM') = ?", [$mes]);
        }

        // Exclude Aprobado by default (unless history mode)
        if (! $history) {
            $aprobadoId = SupervisionEstado::where('nombre', 'Aprobado')->value('id');
            if ($aprobadoId) {
                $query->where(function ($q) use ($aprobadoId) {
                    $q->doesntHave('supervision')
                      ->orWhereHas('supervision', fn ($s) => $s->where('estado_id', '!=', $aprobadoId));
                });
            }
        }

        // Filter by estado_id
        if ($estadoId) {
            $query->whereHas('supervision', fn ($q) => $q->where('estado_id', $estadoId));
        }

        $items = $query->orderBy('installations.fecha', 'desc')->paginate(50);

        return response()->json($items);
    }

    /* ─────────────────────────────────────────────────────────
     |  POST /supervisions/{id}/start   (kept for compat)
     |  Supervisor assigned only
     ────────────────────────────────────────────────────────── */
    public function start(Request $request, int $id): JsonResponse
    {
        $supervision = Supervision::findOrFail($id);
        $this->authorizeAssigned($request->user(), $supervision);

        $enProceso = SupervisionEstado::where('nombre', 'En Proceso')->first()
            ?? SupervisionEstado::orderBy('orden')->skip(1)->first();

        $supervision->update([
            'estado'    => 'en_proceso',
            'estado_id' => $enProceso?->id ?? $supervision->estado_id,
        ]);

        $supervision->load('estadoSupervision');

        return response()->json([
            'success' => true,
            'message' => 'Supervisión iniciada.',
            'data'    => $supervision,
        ]);
    }

    /* ─────────────────────────────────────────────────────────
     |  POST /supervisions/{id}/complete
     |  Supervisor assigned only — requires photos
     ────────────────────────────────────────────────────────── */
    public function complete(Request $request, int $id): JsonResponse
    {
        $supervision = Supervision::findOrFail($id);
        $this->authorizeAssigned($request->user(), $supervision);

        if ($supervision->estado === 'completado') {
            throw ValidationException::withMessages([
                'estado' => ['Esta supervisión ya fue completada.'],
            ]);
        }

        if (! $supervision->hasPhotos()) {
            throw ValidationException::withMessages([
                'fotos' => ['Debe subir al menos una foto de evidencia antes de completar.'],
            ]);
        }

        $request->validate([
            'comentario' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $supervision) {
            $finalizado = SupervisionEstado::where('nombre', 'Finalizado')->first()
                ?? SupervisionEstado::orderBy('orden')->skip(2)->first();

            $supervision->update([
                'estado'     => 'completado',
                'estado_id'  => $finalizado?->id ?? $supervision->estado_id,
                'comentario' => $request->input('comentario', $supervision->comentario),
            ]);

            // Notificar a todos los admins
            $admins = User::where('role', 'admin')->pluck('id');
            $installation = $supervision->installation()->with('client:id,nombres,apellidos')->first();

            foreach ($admins as $adminId) {
                InternalNotification::create([
                    'user_id' => $adminId,
                    'tipo'    => 'supervision_completada',
                    'titulo'  => 'Supervisión completada',
                    'mensaje' => "La supervisión de {$installation->client->nombres} {$installation->client->apellidos} fue completada por {$supervision->supervisor->name}.",
                    'data'    => [
                        'supervision_id'  => $supervision->id,
                        'installation_id' => $supervision->installation_id,
                    ],
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Supervisión completada correctamente.',
            'data'    => $supervision->fresh(['photos', 'supervisor:id,name', 'estadoSupervision']),
        ]);
    }

    /* ─────────────────────────────────────────────────────────
     |  POST /supervisions/{id}/photos
     |  Supervisor assigned only
     ────────────────────────────────────────────────────────── */
    public function uploadPhotos(Request $request, int $id): JsonResponse
    {
        $supervision = Supervision::findOrFail($id);
        $this->authorizeAssigned($request->user(), $supervision);

        $request->validate([
            'fotos'   => ['required', 'array', 'min:1', 'max:10'],
            'fotos.*' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
            'tipo'    => ['nullable', 'string', 'in:general,fachada,conexiones'],
        ], [
            'fotos.required' => 'Seleccione al menos una foto.',
            'fotos.*.max'    => 'Cada foto no debe superar 4 MB.',
        ]);

        $tipo = $request->input('tipo', 'general');

        $photos = [];
        foreach ($request->file('fotos') as $file) {
            $path = $file->store("supervisions/{$id}/photos", 'public');
            $photos[] = $supervision->photos()->create(['photo_path' => $path, 'tipo' => $tipo]);
        }

        // Append URL accessor
        foreach ($photos as $photo) {
            $photo->append('url');
        }

        return response()->json([
            'success' => true,
            'message' => count($photos) . ' foto(s) subida(s) correctamente.',
            'data'    => $photos,
        ]);
    }

    /* ─────────────────────────────────────────────────────────
     |  DELETE /supervisions/{id}/photos/{photoId}
     ────────────────────────────────────────────────────────── */
    public function destroyPhoto(Request $request, int $id, int $photoId): JsonResponse
    {
        $supervision = Supervision::findOrFail($id);
        $this->authorizeAssigned($request->user(), $supervision);

        $photo = $supervision->photos()->findOrFail($photoId);
        Storage::disk('public')->delete($photo->photo_path);
        $photo->delete();

        return response()->json([
            'success' => true,
            'message' => 'Foto eliminada.',
        ]);
    }

    /* ─────────────────────────────────────────────────────────
     |  GET /supervisions/supervisors
     |  Admin only — list users with role=supervisor
     ────────────────────────────────────────────────────────── */
    public function supervisors(Request $request): JsonResponse
    {
        $supervisors = User::where('role', 'supervisor')
            ->where('active', true)
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return response()->json($supervisors);
    }

    /* ─── Private ────────────────────────────────────────── */

    private function authorizeAccess(User $user, Supervision $supervision): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if (! $supervision->isAssignedTo($user)) {
            abort(403, 'No tienes permiso para ver esta supervisión.');
        }
    }

    private function authorizeAssigned(User $user, Supervision $supervision): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if (! $supervision->isAssignedTo($user)) {
            abort(403, 'Solo el supervisor asignado puede realizar esta acción.');
        }
    }
}
