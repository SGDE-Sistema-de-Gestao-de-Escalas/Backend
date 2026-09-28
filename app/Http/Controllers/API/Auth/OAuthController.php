<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class OAuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function redirect(string $provider)
    {
        if (!in_array($provider, ['google', 'azure'])) {
            return response()->json(['message' => 'Provider não suportado.'], 400);
        }

        return response()->json([
            'url' => Socialite::driver($provider)->stateless()->redirect()->getTargetUrl(),
        ]);
    }

    public function externalLogin(string $provider)
    {
        if (!in_array($provider, ['google', 'azure'])) {
            return response()->json(['message' => 'Provider não suportado.'], 400);
        }

        return Socialite::driver($provider)->stateless()->redirect();
    }

    public function externalLoginCallback(string $provider, Request $request)
    {
        if (!in_array($provider, ['google', 'azure'])) {
            return response()->json(['message' => 'Provider não suportado.'], 400);
        }

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
            
            $token = $this->authService->handleOAuthCallback($provider, $socialUser);

            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000') . '/auth/callback?token=' . $token;
            return redirect()->away($frontendUrl);
            
        } catch (\Exception $e) {
            $frontendErrorUrl = env('FRONTEND_URL', 'http://localhost:3000') . '/auth/callback?error=' . urlencode($e->getMessage());
            return redirect()->away($frontendErrorUrl);
        }
    }
}

