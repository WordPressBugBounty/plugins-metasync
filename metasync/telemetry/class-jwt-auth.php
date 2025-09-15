<?php
/**
 * JWT Authentication Class for Telemetry API
 *
 * Handles JWT token creation and validation for secure telemetry transmission
 * Compatible with PHP 7.1+
 *
 * @package     Search Engine Labs SEO
 * @subpackage  Telemetry
 * @since       1.0.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * JWT Authentication Class
 * 
 * Provides JWT token handling for secure API communication
 * Uses base64 encoding/decoding for PHP 7.1 compatibility
 */
class Metasync_JWT_Auth {

    /**
     * JWT Secret Key
     * @var string
     */
    private $secret_key;

    /**
     * JWT Algorithm (using HS256 for PHP 7.1 compatibility)
     * @var string
     */
    private $algorithm = 'HS256';

    /**
     * Token expiration time (1 hour)
     * @var int
     */
    private $expiration_time = 3600;

    /**
     * Constructor
     * 
     * @param string $secret_key The secret key for JWT signing
     */
    public function __construct($secret_key = '') {
        $this->secret_key = $secret_key ?: $this->get_secret_key();
    }

    /**
     * Get or generate secret key
     * 
     * @return string
     */
    private function get_secret_key() {
        $option_name = 'metasync_telemetry_jwt_secret';
        $secret = get_option($option_name);
        
        if (empty($secret)) {
            // Generate a new secret key
            $secret = $this->generate_secret_key();
            update_option($option_name, $secret);
        }
        
        return $secret;
    }

    /**
     * Generate a secure random secret key
     * 
     * @return string
     */
    private function generate_secret_key() {
        // For PHP 7.1 compatibility, use openssl_random_pseudo_bytes or fallback
        if (function_exists('random_bytes')) {
            return base64_encode(random_bytes(32));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            return base64_encode(openssl_random_pseudo_bytes(32));
        } else {
            // Fallback for older systems
            return base64_encode(wp_generate_password(32, false));
        }
    }

    /**
     * Create a JWT token
     * 
     * @param array $payload The payload to include in the token
     * @return string The JWT token
     */
    public function create_token($payload = array()) {
        $header = array(
            'typ' => 'JWT',
            'alg' => $this->algorithm
        );

        $payload = array_merge($payload, array(
            'iss' => home_url(), // Issuer
            'aud' => 'dash-api-telemetry', // Audience
            'iat' => time(), // Issued at
            'exp' => time() + $this->expiration_time, // Expiration
            'site_url' => home_url(),
            'plugin_version' => defined('METASYNC_VERSION') ? METASYNC_VERSION : '1.0.0'
        ));

        $header_encoded = $this->base64url_encode(json_encode($header));
        $payload_encoded = $this->base64url_encode(json_encode($payload));
        
        $signature = $this->create_signature($header_encoded . '.' . $payload_encoded);
        $signature_encoded = $this->base64url_encode($signature);

        return $header_encoded . '.' . $payload_encoded . '.' . $signature_encoded;
    }

    /**
     * Verify a JWT token
     * 
     * @param string $token The JWT token to verify
     * @return array|false The decoded payload or false on failure
     */
    public function verify_token($token) {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return false;
        }

        list($header_encoded, $payload_encoded, $signature_encoded) = $parts;

        // Verify signature
        $signature = $this->base64url_decode($signature_encoded);
        $expected_signature = $this->create_signature($header_encoded . '.' . $payload_encoded);
        
        if (!hash_equals($signature, $expected_signature)) {
            return false;
        }

        // Decode payload
        $payload = json_decode($this->base64url_decode($payload_encoded), true);
        
        if (!$payload) {
            return false;
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return false;
        }

        return $payload;
    }

    /**
     * Create signature for JWT
     * 
     * @param string $data The data to sign
     * @return string The signature
     */
    private function create_signature($data) {
        return hash_hmac('sha256', $data, $this->secret_key, true);
    }

    /**
     * Base64 URL-safe encode
     * 
     * @param string $data Data to encode
     * @return string Encoded data
     */
    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL-safe decode
     * 
     * @param string $data Data to decode
     * @return string Decoded data
     */
    private function base64url_decode($data) {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }

    /**
     * Get authorization header for API requests
     * 
     * @param array $payload Additional payload data
     * @return string The authorization header value
     */
    public function get_auth_header($payload = array()) {
        $token = $this->create_token($payload);
        return 'Bearer ' . $token;
    }

    /**
     * Check if token is expired
     * 
     * @param string $token The JWT token
     * @return bool True if expired, false otherwise
     */
    public function is_token_expired($token) {
        $payload = $this->verify_token($token);
        if (!$payload) {
            return true;
        }
        
        return isset($payload['exp']) && $payload['exp'] < time();
    }
}
