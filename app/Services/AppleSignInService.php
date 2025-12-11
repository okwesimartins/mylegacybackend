<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

class AppleSignInService
{
    /**
     * Verify the Apple identity token and return its claims.
     *
     * @throws \Exception if token invalid
     */
    public function verifyIdentityToken(string $identityToken): \stdClass
    {
        // 1. Get Apple's public keys (JWKS), cached
        $jwks = Cache::remember('apple_jwks', 60, function () {
            $response = Http::get('https://appleid.apple.com/auth/keys');
            if (!$response->ok()) {
                throw new Exception('Unable to fetch Apple public keys');
            }
            return $response->json();
        });

        // 2. Parse keys
        $publicKeys = JWK::parseKeySet($jwks);

        // 3. Decode & verify signature (RS256)
        //    firebase/php-jwt will pick the right key using 'kid' from token header
        $claims = JWT::decode($identityToken, $publicKeys);

        // 4. Additional checks: iss, aud, exp
        $this->validateClaims($claims);

        return $claims;
    }

  protected function validateClaims(\stdClass $claims): void
{
    if (!isset($claims->iss) || $claims->iss !== 'https://appleid.apple.com') {
        throw new \Exception('Invalid issuer: ' . ($claims->iss ?? 'null'));
    }

    $expectedAud = config('services.apple.client_id');
    $tokenAud    = $claims->aud ?? null;

    if (!$tokenAud || $tokenAud !== $expectedAud) {
        // 👇 put both values into the error message
        throw new \Exception(
            'Invalid audience. token_aud=' . ($tokenAud ?? 'null') .
            ' expected_aud=' . ($expectedAud ?? 'null')
        );
    }

    if (!isset($claims->exp) || $claims->exp < time()) {
        throw new \Exception('Token has expired');
    }
}

}
