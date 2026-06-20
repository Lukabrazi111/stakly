<?php

namespace App\Services\Provider;

use GuzzleHttp\RequestOptions;
use SocialiteProviders\Faceit\Provider as FaceitSocialiteProvider;

/**
 * Local FACEIT Socialite provider — PKCE-aware with retained
 * `client_secret_basic` client authentication.
 *
 * FACEIT's App Studio issues confidential PKCE clients: the token
 * exchange needs BOTH a `code_verifier` in the body (PKCE proof) AND
 * the Basic-auth header keyed on `client_id:client_secret` (confidential
 * client authentication). The Phase 0 research assumed PKCE-public
 * (no secret) — that was wrong; FACEIT stacks both defenses.
 *
 * The `socialiteproviders/faceit` package (last tagged Sep 2022) sends
 * Basic auth correctly but drops `code_verifier` from the body, so the
 * token endpoint rejects the exchange when PKCE is required. This
 * subclass re-implements `getTokenFields()` to include `code_verifier`
 * (when PKCE is engaged) plus the `redirect_uri` required by the
 * authorization-code grant. `getAccessTokenResponse()` keeps the
 * package's original Basic-auth header.
 *
 * Wired in via `AppServiceProvider`'s `SocialiteWasCalled` listener,
 * replacing the package's `Provider` class as the driver for `'faceit'`.
 */
class FaceitProvider extends FaceitSocialiteProvider
{
    /**
     * Always use PKCE for this driver. Set as a property default (not via
     * `->enablePKCE()` per-call) so both the redirect and callback paths
     * — each gets a fresh provider instance — have PKCE on without the
     * caller having to remember. FACEIT's only OAuth2 client variant is
     * PKCE-enabled, so there's no reason to ever toggle this off.
     */
    protected $usesPKCE = true;

    protected function getTokenFields($code)
    {
        $fields = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUrl,
        ];

        if ($this->usesPKCE()) {
            $fields['code_verifier'] = $this->request->session()->pull('code_verifier');
        }

        return $fields;
    }

    public function getAccessTokenResponse($code)
    {
        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            RequestOptions::FORM_PARAMS => $this->getTokenFields($code),
            RequestOptions::HEADERS => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret),
            ],
        ]);

        return $this->credentialsResponseBody = json_decode((string) $response->getBody(), true);
    }
}
