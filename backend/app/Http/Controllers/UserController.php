<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * GET /admin/users
     * List all users with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                                    ->orWhere('email', 'ilike', "%{$search}%")
                                    ->orWhere('dni', 'ilike', "%{$search}%");
            });
        }

        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }

        if ($request->has('active')) {
            $query->where('active', filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
        }

        $users = $query
            ->withCount('clients')
            ->orderBy('name')
            ->paginate($request->input('per_page', 20));

        return response()->json($users);
    }

    /**
     * GET /admin/users/{user}
     */
    public function show(User $user): JsonResponse
    {
        $user->loadCount('clients');

        return response()->json([
            'success' => true,
            'data'    => $user,
        ]);
    }

    /**
     * POST /admin/users
     * Create a new user.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:100'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'dni'      => ['nullable', 'regex:/^\d{7,8}$/', 'unique:users,dni'],
            'password' => ['required', 'string', 'min:5', 'regex:/[a-z]/', 'regex:/[A-Z]/'],
            'role'     => ['required', 'in:admin,vendedora,supervisor'],
            'active'   => ['nullable', 'boolean'],
        ], [
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 5 caracteres.',
            'password.regex' => 'La contraseña debe incluir letras minúsculas y mayúsculas.',
        ]);

        if (in_array($data['role'], ['vendedora', 'supervisor'], true) && empty($data['dni'])) {
            return response()->json([
                'success' => false,
                'message' => 'El DNI es obligatorio para vendedora y supervisor.',
                'errors'  => [
                    'dni' => ['El DNI es obligatorio para este rol.'],
                ],
            ], 422);
        }

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'dni'      => $data['dni'] ?? null,
            'password' => $data['password'], // hashed by cast
            'role'     => $data['role'],
            'active'   => $data['active'] ?? true,
        ]);

        $user->loadCount('clients');

        return response()->json([
            'success' => true,
            'message' => 'Usuario creado correctamente.',
            'data'    => $user,
        ], 201);
    }

    /**
     * PUT /admin/users/{user}
     * Update user data. Password is optional.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        // Prevent deactivating your own account
        if ($user->id === $request->user()->id && $request->has('active') && !$request->boolean('active')) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes desactivar tu propia cuenta.',
            ], 422);
        }

        $data = $request->validate([
            'name'     => ['sometimes', 'string', 'max:100'],
            'email'    => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'dni'      => ['nullable', 'regex:/^\d{7,8}$/', Rule::unique('users', 'dni')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:5', 'regex:/[a-z]/', 'regex:/[A-Z]/'],
            'role'     => ['sometimes', 'in:admin,vendedora,supervisor'],
            'active'   => ['nullable', 'boolean'],
        ], [
            'password.min' => 'La contraseña debe tener al menos 5 caracteres.',
            'password.regex' => 'La contraseña debe incluir letras minúsculas y mayúsculas.',
        ]);

        // Prevent changing own role
        if ($user->id === $request->user()->id && isset($data['role']) && $data['role'] !== $user->role) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes cambiar tu propio rol.',
            ], 422);
        }

        // Remove password if empty/null
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $effectiveRole = $data['role'] ?? $user->role;
        $effectiveDni  = array_key_exists('dni', $data) ? $data['dni'] : $user->dni;

        if (in_array($effectiveRole, ['vendedora', 'supervisor'], true) && empty($effectiveDni)) {
            return response()->json([
                'success' => false,
                'message' => 'El DNI es obligatorio para vendedora y supervisor.',
                'errors'  => [
                    'dni' => ['El DNI es obligatorio para este rol.'],
                ],
            ], 422);
        }

        $user->update($data);
        $user->loadCount('clients');

        return response()->json([
            'success' => true,
            'message' => 'Usuario actualizado correctamente.',
            'data'    => $user,
        ]);
    }

    /**
     * DELETE /admin/users/{user}
     * Soft-deactivate (toggle active=false) instead of hard delete.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes eliminar tu propia cuenta.',
            ], 422);
        }

        $user->update(['active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Usuario desactivado correctamente.',
        ]);
    }
}
