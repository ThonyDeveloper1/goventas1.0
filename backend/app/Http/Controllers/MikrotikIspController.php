<?php

namespace App\Http\Controllers;

use App\Services\MikrotikIspService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MikrotikIspController extends Controller
{
    public function __construct(private readonly MikrotikIspService $isp) {}

    /* ─── Online Users ────────────────────────────────────── */

    public function onlineUsers(int $routerId): JsonResponse
    {
        try {
            $users = $this->isp->getOnlineUsers($routerId);
            return response()->json($users);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 503);
        }
    }

    /* ─── Sync DB ↔ MikroTik ─────────────────────────────── */

    public function sync(int $routerId): JsonResponse
    {
        try {
            $result = $this->isp->syncRouterWithDb($routerId);
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 503);
        }
    }

    /* ─── Ensure Profiles ─────────────────────────────────── */

    public function ensureProfiles(int $routerId): JsonResponse
    {
        try {
            $result = $this->isp->ensureAllProfiles($routerId);
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 503);
        }
    }

    /* ─── Corte Moroso ────────────────────────────────────── */

    public function corteMoroso(int $routerId): JsonResponse
    {
        try {
            $entries = $this->isp->getMoresoFromRouter($routerId);
            return response()->json($entries);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 503);
        }
    }

    /* ─── Sync Morosos + IPs to DB ────────────────────────── */

    public function syncMorosos(int $routerId): JsonResponse
    {
        try {
            $result = $this->isp->syncMorososToDb($routerId);
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 503);
        }
    }

    /* ─── PPP Profiles CRUD ───────────────────────────────── */

    public function profiles(int $routerId): JsonResponse
    {
        try {
            $profiles = $this->isp->getProfiles($routerId);
            return response()->json($profiles);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 503);
        }
    }

    public function createProfile(Request $request, int $routerId): JsonResponse
    {
        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:150'],
            'rate_limit'     => ['nullable', 'string', 'max:100', 'regex:/^\d+(?:\.\d+)?[KMG]?\/\d+(?:\.\d+)?[KMG]?$/i'],
            'local_address'  => ['nullable', 'string', 'max:120'],
            'remote_address' => ['nullable', 'string', 'max:120'],
            'dns_server'     => ['nullable', 'string', 'max:200'],
            'comment'        => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $profile = $this->isp->createProfile($routerId, $validated);
            return response()->json($profile, 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function updateProfile(Request $request, int $routerId, string $profileId): JsonResponse
    {
        $validated = $request->validate([
            'name'           => ['nullable', 'string', 'max:150'],
            'rate_limit'     => ['nullable', 'string', 'max:100'],
            'local_address'  => ['nullable', 'string', 'max:120'],
            'remote_address' => ['nullable', 'string', 'max:120'],
            'dns_server'     => ['nullable', 'string', 'max:200'],
            'comment'        => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $profile = $this->isp->updateProfile($routerId, $profileId, $validated);
            return response()->json($profile);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function deleteProfile(int $routerId, string $profileId): JsonResponse
    {
        try {
            $result = $this->isp->deleteProfile($routerId, $profileId);
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /* ─── PPP Secrets (read-only) ─────────────────────────── */

    public function secrets(int $routerId): JsonResponse
    {
        try {
            $secrets = $this->isp->getSecrets($routerId);
            return response()->json($secrets);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 503);
        }
    }

    /* ─── IP Pools ────────────────────────────────────────── */

    public function pools(int $routerId): JsonResponse
    {
        try {
            $pools = $this->isp->getPools($routerId);
            return response()->json($pools);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 503);
        }
    }

    public function poolAvailability(int $routerId): JsonResponse
    {
        try {
            $availability = $this->isp->getPoolAvailability($routerId);
            return response()->json($availability);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 503);
        }
    }

    /* ─── Morosos por Vendedora ───────────────────────────── */

    public function morososPorVendedora(): JsonResponse
    {
        try {
            $data = $this->isp->countMoresosByVendedora();
            return response()->json($data);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
