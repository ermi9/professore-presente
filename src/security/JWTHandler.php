<?php

class JWTHandler {
    private $secret_key = 'your_super_secret_key_change_me';
    private $expiration = 86400; // 24 hours

    // Create a JWT token
    public function create($payload) {
        $payload['iat'] = time();
        $payload['exp'] = time() + $this->expiration;

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];

        $header_encoded = $this->base64UrlEncode(json_encode($header));
        $payload_encoded = $this->base64UrlEncode(json_encode($payload));

        $signature = hash_hmac(
            'sha256',
            $header_encoded . '.' . $payload_encoded,
            $this->secret_key,
            true
        );
        $signature_encoded = $this->base64UrlEncode($signature);

        return $header_encoded . '.' . $payload_encoded . '.' . $signature_encoded;
    }

    // Verify and decode a JWT token
    public function verify($token) {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        $header_encoded = $parts[0];
        $payload_encoded = $parts[1];
        $signature_provided = $parts[2];

        $signature_calculated = hash_hmac(
            'sha256',
            $header_encoded . '.' . $payload_encoded,
            $this->secret_key,
            true
        );
        $signature_calculated_encoded = $this->base64UrlEncode($signature_calculated);

        if (!hash_equals($signature_provided, $signature_calculated_encoded)) {
            return null;
        }

        $payload = json_decode($this->base64UrlDecode($payload_encoded), true);

        if ($payload === null) {
            return null;
        }

        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 4 - strlen($data) % 4));
    }

    public static function getTokenFromHeader() {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            if (preg_match('/^Bearer\s+(.+)$/', $headers['Authorization'], $matches)) {
                return $matches[1];
            }
        }
        return null;
    }
}
?>
