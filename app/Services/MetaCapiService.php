<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MetaCapiService
{
    public function send(
        string $eventName,
        string $eventId,
        array $customData = [],
        ?Request $request = null,
        ?string $sourceUrl = null
    ): bool {
        if (Setting::getValue('meta_capi_enabled', '0') !== '1') {
            return false;
        }

        $pixelId = trim((string) Setting::getValue('meta_pixel_id', ''));
        $token = trim((string) Setting::getValue('meta_access_token', ''));

        $version = trim(
            (string) Setting::getValue('meta_api_version', 'v24.0')
        ) ?: 'v24.0';

        $version = Str::startsWith($version, 'v')
            ? $version
            : 'v'.$version;

        if ($pixelId === '' || $token === '') {
            return false;
        }

        $request ??= request();

        /*
        |--------------------------------------------------------------------------
        | Customer information
        |--------------------------------------------------------------------------
        */

        $email = $this->normalizeEmail(
            (string) $request->input('email', '')
        );

        $phone = $this->normalizePhone(
            (string) $request->input('phone', '')
        );

        [$firstName, $lastName] = $this->splitCustomerName(
            (string) $request->input('customer_name', '')
        );

        /*
        |--------------------------------------------------------------------------
        | Meta browser identifiers
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | _fbc and _fbp must NOT be hashed.
        | _fbc must NOT be converted to lowercase.
        |
        */

        $fbp = $this->cleanMetaCookie(
            $request->cookie('_fbp')
        );

        $fbc = $this->resolveFbc(
            $request,
            $sourceUrl
        );

        /*
        |--------------------------------------------------------------------------
        | Stable first-party ID
        |--------------------------------------------------------------------------
        */

        $sessionId = '';

        try {
            $sessionId = (string) $request->session()->getId();
        } catch (\Throwable $e) {
            $sessionId = '';
        }

        /*
        |--------------------------------------------------------------------------
        | User data
        |--------------------------------------------------------------------------
        */

        $userData = array_filter([
            /*
             * These values must NOT be hashed.
             */
            'client_ip_address' => $request->ip(),
            'client_user_agent' => $request->userAgent(),

            'fbp' => $fbp,
            'fbc' => $fbc,

            /*
             * Personally identifiable matching fields are SHA256 hashed.
             */
            'em' => $this->hashedArray($email),

            'ph' => $this->hashedArray($phone),

            'fn' => $this->hashedArray($firstName),

            'ln' => $this->hashedArray($lastName),

            /*
             * Store country is Bangladesh.
             */
            'country' => $this->hashedArray('bd'),

            /*
             * First-party external identifier.
             */
            'external_id' => $sessionId !== ''
                ? [hash('sha256', $sessionId)]
                : null,

        ], static function ($value) {
            return $value !== null
                && $value !== ''
                && $value !== [];
        });

        /*
        |--------------------------------------------------------------------------
        | Meta event payload
        |--------------------------------------------------------------------------
        */

        $payload = [
            'data' => [
                [
                    'event_name' => $eventName,

                    'event_time' => now()->timestamp,

                    /*
                     * Same Event ID is used by Browser Pixel and CAPI.
                     * This is required for deduplication.
                     */
                    'event_id' => $eventId,

                    'action_source' => 'website',

                    'event_source_url' => $sourceUrl
                        ?: $request->fullUrl(),

                    'user_data' => $userData,

                    'custom_data' => $customData,
                ],
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Meta Test Event Code
        |--------------------------------------------------------------------------
        */

        $testCode = trim(
            (string) Setting::getValue(
                'meta_test_event_code',
                ''
            )
        );

        if ($testCode !== '') {
            $payload['test_event_code'] = $testCode;
        }

        /*
        |--------------------------------------------------------------------------
        | Send to Meta
        |--------------------------------------------------------------------------
        */

        try {
            $url =
                "https://graph.facebook.com/"
                .$version
                ."/"
                .$pixelId
                ."/events?access_token="
                .urlencode($token);

            $response = Http::asJson()
                ->acceptJson()
                ->timeout(12)
                ->post($url, $payload);

            if (! $response->successful()) {
                Log::warning('Meta CAPI failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'event' => $eventName,
                ]);
            }

            return $response->successful();

        } catch (\Throwable $e) {

            Log::warning('Meta CAPI exception', [
                'error' => $e->getMessage(),
                'event' => $eventName,
            ]);

            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Meta Click ID
    |--------------------------------------------------------------------------
    |
    | Priority:
    |
    | 1. Existing _fbc cookie
    | 2. Explicit fbc request value
    | 3. fbclid from current request
    | 4. fbclid from original event source URL
    |
    */

    private function resolveFbc(
        Request $request,
        ?string $sourceUrl = null
    ): ?string {
        /*
         * Existing Meta cookie is the best source.
         */
        $cookieFbc = $this->cleanMetaCookie(
            $request->cookie('_fbc')
        );

        if ($cookieFbc !== null) {
            return $cookieFbc;
        }

        /*
         * Allow an explicit fbc value if supplied in future integrations.
         */
        $requestFbc = $this->cleanMetaCookie(
            $request->input('fbc')
        );

        if ($requestFbc !== null) {
            return $requestFbc;
        }

        /*
         * Current URL/query/body fbclid.
         */
        $fbclid = trim(
            (string) $request->input(
                'fbclid',
                $request->query('fbclid', '')
            )
        );

        /*
         * For browser events our /meta/event request itself does not contain
         * the original URL query parameters, so inspect event_source_url too.
         */
        if ($fbclid === '' && $sourceUrl) {
            $fbclid = $this->extractFbclidFromUrl(
                $sourceUrl
            );
        }

        if ($fbclid === '') {
            return null;
        }

        /*
         * Protect against malformed/unreasonably large input.
         */
        if (mb_strlen($fbclid) > 1000) {
            return null;
        }

        /*
         * Preserve fbclid exactly.
         * It is case-sensitive and must NOT be normalized/lowercased.
         */
        return 'fb.1.'
            .now()->valueOf()
            .'.'
            .$fbclid;
    }

    /*
    |--------------------------------------------------------------------------
    | Read fbclid from an event source URL
    |--------------------------------------------------------------------------
    */

    private function extractFbclidFromUrl(
        string $url
    ): string {
        try {
            $query = parse_url(
                $url,
                PHP_URL_QUERY
            );

            if (! is_string($query) || $query === '') {
                return '';
            }

            parse_str($query, $parameters);

            $fbclid = $parameters['fbclid'] ?? '';

            if (! is_string($fbclid)) {
                return '';
            }

            return trim($fbclid);

        } catch (\Throwable $e) {
            return '';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Meta Cookie Cleanup
    |--------------------------------------------------------------------------
    */

    private function cleanMetaCookie(
        mixed $value
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        /*
         * Meta identifiers are case-sensitive.
         * Do not lowercase or hash them.
         */
        return $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Email normalization
    |--------------------------------------------------------------------------
    */

    private function normalizeEmail(
        string $email
    ): string {
        $email = trim($email);

        if ($email === '') {
            return '';
        }

        return mb_strtolower($email);
    }

    /*
    |--------------------------------------------------------------------------
    | Bangladesh phone normalization
    |--------------------------------------------------------------------------
    |
    | Examples:
    |
    | 01735076504
    |      ↓
    | 8801735076504
    |
    | +8801735076504
    |      ↓
    | 8801735076504
    |
    */

    private function normalizePhone(
        string $phone
    ): string {
        $phone = preg_replace(
            '/\D+/',
            '',
            $phone
        ) ?? '';

        if ($phone === '') {
            return '';
        }

        /*
         * Remove international dialing prefix.
         *
         * 0088017...
         * becomes
         * 88017...
         */
        if (str_starts_with($phone, '00')) {
            $phone = substr($phone, 2);
        }

        /*
         * Bangladesh local format:
         *
         * 017XXXXXXXX
         * becomes
         * 88017XXXXXXXX
         */
        if (str_starts_with($phone, '0')) {
            $phone = '880'.substr($phone, 1);
        }

        /*
         * Some customers may enter:
         *
         * 17XXXXXXXX
         *
         * without the leading zero.
         */
        if (
            strlen($phone) === 10
            && str_starts_with($phone, '1')
        ) {
            $phone = '880'.$phone;
        }

        return $phone;
    }

    /*
    |--------------------------------------------------------------------------
    | Customer name normalization
    |--------------------------------------------------------------------------
    |
    | Example:
    |
    | Abdullah Al Sabbir
    |
    | fn = abdullah
    | ln = sabbir
    |
    */

    private function splitCustomerName(
        string $name
    ): array {
        $name = trim($name);

        if ($name === '') {
            return ['', ''];
        }

        /*
         * Collapse repeated spaces.
         */
        $name = preg_replace(
            '/\s+/u',
            ' ',
            $name
        ) ?? $name;

        $name = mb_strtolower(
            trim($name)
        );

        $parts = preg_split(
            '/\s+/u',
            $name
        );

        if (! is_array($parts) || count($parts) === 0) {
            return ['', ''];
        }

        $parts = array_values(
            array_filter(
                $parts,
                fn ($part) => trim((string) $part) !== ''
            )
        );

        if (count($parts) === 0) {
            return ['', ''];
        }

        if (count($parts) === 1) {
            return [
                $parts[0],
                '',
            ];
        }

        return [
            $parts[0],
            $parts[count($parts) - 1],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | SHA256 helper
    |--------------------------------------------------------------------------
    */

    private function hashedArray(
        ?string $value
    ): ?array {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return [
            hash(
                'sha256',
                $value
            ),
        ];
    }
}