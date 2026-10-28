<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

class AuthController extends Controller
{
    /** Failed passwords before an account is locked, and for how long (seconds). */
    private const MAX_FAILED_LOGINS = 5;

    private const LOCKOUT_SECONDS = 15 * 60;

    public function login(LoginRequest $request): JsonResponse
    {
        $login = (string) $request->string('username');

        // Through Cloudflare Tunnel the visitor's IP must be known: falling back
        // to cloudflared's 127.0.0.1 would put every outside visitor under one
        // lockout key, letting anyone lock an account for everybody. Refuse
        // instead — nothing is counted, so this can't be used to lock anyone.
        if ($this->visitorIpUnknownBehindCloudflare($request)) {
            throw ValidationException::withMessages([
                'username' => ['Login dari luar kantor sedang tidak dapat diproses (alamat IP tidak terbaca). Coba lagi nanti atau hubungi administrator.'],
            ])->status(503);
        }

        // Lockout per account AND per visitor IP: guessing someone's password
        // from one place locks that account only for that place, so nobody can
        // lock a colleague out of STOCKWISE on purpose. Unknown usernames are
        // counted the same way, so the response never reveals which ones exist.
        $key = sha1(Str::lower(trim($login)).'|'.$request->ip());
        $failedKey = 'login-failed:'.$key;
        $lockKey = 'login-locked:'.$key;
        if (RateLimiter::tooManyAttempts($lockKey, 1)) {
            $minutes = (int) ceil(RateLimiter::availableIn($lockKey) / 60);
            throw ValidationException::withMessages([
                'username' => ["Terlalu banyak percobaan login yang gagal. Coba lagi dalam {$minutes} menit."],
            ])->status(429);
        }

        $user = User::where('username', $login)->orWhere('email', $login)->first();

        if (! $user || ! Hash::check((string) $request->string('password'), $user->password)) {
            // the 15 minutes run from the 5th failure, not from the first one
            if (RateLimiter::hit($failedKey, self::LOCKOUT_SECONDS) >= self::MAX_FAILED_LOGINS) {
                RateLimiter::clear($failedKey);
                RateLimiter::hit($lockKey, self::LOCKOUT_SECONDS);
            }
            throw ValidationException::withMessages([
                'username' => ['Username/email atau password salah.'],
            ]);
        }

        RateLimiter::clear($failedKey);

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'username' => ['Akun dinonaktifkan. Hubungi administrator.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $user->load('site', 'roles');

        // Explicit, limited ability (never the default ['*'], which would also
        // satisfy `abilities:agent:sync`) and a lifetime — a leaked token from
        // a shared warehouse PC stops working at the end of the day.
        $ttlHours = (int) config('stockwise.login_token_ttl_hours');
        $token = $user->createToken(
            (string) ($request->string('device_name')->value() ?: 'stockwise-spa'),
            ['app'],
            $ttlHours > 0 ? now()->addHours($ttlHours) : null,
        )->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    /**
     * True for a request that came through Cloudflare (cloudflared on loopback,
     * edge headers present) whose visitor IP can't be trusted: no usable
     * X-Forwarded-For entry (ip() fell back to a trusted proxy), no
     * CF-Connecting-IP to confirm it, or one that disagrees with it. Direct
     * LAN clients and tools on the server itself (no Cloudflare headers) are
     * not affected.
     */
    private function visitorIpUnknownBehindCloudflare(Request $request): bool
    {
        if (! $request->isFromTrustedProxy()) {
            return false; // direct client: REMOTE_ADDR is the visitor
        }
        $headers = $request->headers;
        if (! $headers->has('CF-Ray') && ! $headers->has('CF-Connecting-IP') && ! $headers->has('CDN-Loop')) {
            return false; // a browser / dev proxy on this machine, not Cloudflare
        }

        $ip = $request->ip();
        if ($ip === null || IpUtils::checkIp($ip, Request::getTrustedProxies())) {
            return true;
        }
        $connecting = $headers->get('CF-Connecting-IP');
        if ($connecting === null) {
            return true; // Cloudflare always sends it: without it there's nothing to check X-Forwarded-For against
        }

        return ! filter_var($connecting, FILTER_VALIDATE_IP) || inet_pton($connecting) !== inet_pton($ip);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()->load('site', 'roles')),
        ]);
    }

    /** Self-service password change; every OTHER session of this user is logged out. */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => UserController::passwordRules(),
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['Password saat ini salah.']]);
        }
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => ['Password baru harus berbeda dari password saat ini.']]);
        }

        $user->forceFill(['password' => $data['password']])->save();
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Password berhasil diganti. Sesi login di perangkat lain sudah diakhiri.']);
    }
}
