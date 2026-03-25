<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignSupervisionRequest;
use App\Models\InternalNotification;
use App\Models\Supervision;
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
            'installation.client:id,nombres,apellidos,dni,telefono_1,direccion,distrito,latitud,longitud',
            'installation.client.photos',
            'installation.vendedora:id,name',
            'supervisor:id,name',
            'photos',
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
            $supervision = Supervision::create([
                'installation_id' => $request->installation_id,
                'supervisor_id'   => $supervisor->id,
                'estado'          => 'pendiente',
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
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Supervisión asignada correctamente.',
            'data'    => $supervision,
        ], 201);
    }

    /* ─────────────────────────────────────────────────────────
     |  POST /supervisions/{id}/start
     |  Supervisor assigned only
     ────────────────────────────────────────────────────────── */
    public function start(Request $request, int $id): JsonResponse
    {
        $supervision = Supervision::findOrFail($id);
        $this->authorizeAssigned($request->user(), $supervision);

        if ($supervision->estado !== 'pendiente') {
            throw ValidationException::withMessages([
                'estado' => ['Solo se puede iniciar una supervisión en estado pendiente.'],
            ]);
        }

        $supervision->update(['estado' => 'en_proceso']);

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
            $supervision->update([
                'estado'     => 'completado',
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
            'data'    => $supervision->fresh(['photos', 'supervisor:id,name']),
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

        if ($supervision->estado === 'completado') {
            throw ValidationException::withMessages([
                'estado' => ['No se pueden agregar fotos a una supervisión completada.'],
            ]);
        }

        $request->validate([
            'fotos'   => ['required', 'array', 'min:1', 'max:10'],
            'fotos.*' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
        ], [
            'fotos.required' => 'Seleccione al menos una foto.',
            'fotos.*.max'    => 'Cada foto no debe superar 4 MB.',
        ]);

        $photos = [];
        foreach ($request->file('fotos') as $file) {
            $path = $file->store("supervisions/{$id}/photos", 'public');
            $photos[] = $supervision->photos()->create(['photo_path' => $path]);
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

        if ($supervision->estado === 'completado') {
            throw ValidationException::withMessages([
                'estado' => ['No se pueden eliminar fotos de una supervisión completada.'],
            ]);
        }

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
