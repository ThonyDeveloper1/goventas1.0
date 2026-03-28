<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /**
     * Login – returns Sanctum token + user data.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'login'    => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:5'],
        ]);

        $login = trim((string) $request->input('login'));
        $loginType = preg_match('/^\d+$/', $login) ? 'dni' : 'email';

        if (preg_match('/^\d+$/', $login)) {
            if (!preg_match('/^\d{7,8}$/', $login)) {
                Log::warning('Login rejected: invalid DNI format', [
                    'login_type' => 'dni',
                    'login_value' => $this->maskLogin($login),
                    'ip' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                ]);

                throw ValidationException::withMessages([
                    'login' => ['El DNI debe tener 7 u 8 números.'],
                ]);
            }

            $user = User::where('dni', $login)->first();
        } else {
            $login = mb_strtolower($login);

            if (!filter_var($login, FILTER_VALIDATE_EMAIL)) {
                Log::warning('Login rejected: invalid email format', [
                    'login_type' => 'email',
                    'login_value' => $this->maskLogin($login),
                    'ip' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                ]);

                throw ValidationException::withMessages([
                    'login' => ['Ingrese un DNI de 7 u 8 dígitos o un correo valido.'],
                ]);
            }

            $user = User::whereRaw('LOWER(email) = ?', [$login])->first();
        }

        if (! $user) {
            Log::warning('Login failed: user not found or not allowed', [
                'login_type' => $loginType,
                'login_value' => $this->maskLogin($login),
                'ip' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);

            throw ValidationException::withMessages([
                'login' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        if (! Hash::check($request->password, $user->password)) {
            Log::warning('Login failed: password mismatch', [
                'user_id' => $user->id,
                'login_type' => $loginType,
                'login_value' => $this->maskLogin($login),
                'ip' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);

            throw ValidationException::withMessages([
                'login' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        Log::info('Login successful', [
            'user_id' => $user->id,
            'role' => $user->role,
            'login_type' => $loginType,
            'login_value' => $this->maskLogin($login),
            'ip' => $request->ip(),
        ]);

        // Revoke all previous tokens for this device
        $user->tokens()->delete();

        // Token expires in 8 hours
        $token = $user->createToken(
            'auth_token',
            ['*'],
            now()->addHours(8)
        )->plainTextToken;

        return response()->json([
            'success' => true,
            'token'   => $token,
            'user'    => $this->userResource($user),
        ]);
    }

    /**
     * Logout – revokes current token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    /**
     * Return authenticated user data.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json(
            $this->userResource($request->user())
        );
    }

    /**
     * Update authenticated user profile.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'dni'   => ['nullable', 'regex:/^\d{7,8}$/', Rule::unique('users', 'dni')->ignore($user->id)],
        ]);

        if (in_array($user->role, ['vendedora', 'supervisor'], true) && empty($data['dni'])) {
            throw ValidationException::withMessages([
                'dni' => ['El DNI es obligatorio para este rol.'],
            ]);
        }

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Perfil actualizado correctamente.',
            'user'    => $this->userResource($user->fresh()),
        ]);
    }

    /**
     * Change authenticated user password.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => [
                'required',
                'string',
                'min:5',
                'confirmed',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
            ],
        ], [
            'current_password.required' => 'La contraseña actual es obligatoria.',
            'password.required' => 'La nueva contraseña es obligatoria.',
            'password.min' => 'La nueva contraseña debe tener al menos 5 caracteres.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
            'password.regex' => 'La nueva contraseña debe incluir letras minúsculas y mayúsculas.',
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña actual no es correcta.'],
            ]);
        }

        $user->update(['password' => $data['password']]);

        return response()->json([
            'success' => true,
            'message' => 'Contraseña actualizada correctamente.',
        ]);
    }

    /**
     * Update authenticated user DNI credentials.
     */
    public function updateDni(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'dni' => ['required', 'regex:/^\d{7,8}$/', Rule::unique('users', 'dni')->ignore($user->id)],
        ]);

        $user->update(['dni' => $data['dni']]);

        return response()->json([
            'success' => true,
            'message' => 'DNI actualizado correctamente.',
            'user'    => $this->userResource($user->fresh()),
        ]);
    }

    /* ─── Private helpers ───────────────────────────────────────── */

    private function userResource(User $user): array
    {
        return [
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'dni'   => $user->dni,
            'role'  => $user->role,
            'active'=> $user->active,
        ];
    }

    private function maskLogin(string $value): string
    {
        $length = mb_strlen($value);

        if ($length <= 2) {
            return str_repeat('*', $length);
        }

        return mb_substr($value, 0, 2) . str_repeat('*', $length - 2);
    }
}
