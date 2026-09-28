<?php
/**
 * FastPix Utilities Class
 * Handles encryption, API credentials, and other utility functions
 */

namespace Fastpix;

if (!defined('WPINC')) {
    die;
}

class Fastpix_Utils {
    
    /**
     * Get encryption key (creates one if doesn't exist)
     */
    public static function get_encryption_key() {
        if (!defined('FASTPIX_ENCRYPTION_KEY')) {
            $key = get_option('fastpix_encryption_key');
            if (!$key) {
                $key = wp_generate_password(64, true, true);
                update_option('fastpix_encryption_key', $key);
            }
            define('FASTPIX_ENCRYPTION_KEY', $key);
        }
        return FASTPIX_ENCRYPTION_KEY;
    }
    
    /**
     * Encrypt a value using AES-256-CBC
     */
    public static function encrypt_value($value) {
        if (empty($value)) {
            return '';
        }
        $key = self::get_encryption_key();
        $method = "AES-256-CBC";
        $ivlen = openssl_cipher_iv_length($method);
        $iv = openssl_random_pseudo_bytes($ivlen);
        $encrypted = openssl_encrypt($value, $method, $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Decrypt a value using AES-256-CBC
     */
    public static function decrypt_value($encrypted_value) {
        if (empty($encrypted_value)) {
            return '';
        }
        $key = self::get_encryption_key();
        $method = "AES-256-CBC";
        $data = base64_decode($encrypted_value);
        $ivlen = openssl_cipher_iv_length($method);
        $iv = substr($data, 0, $ivlen);
        $encrypted = substr($data, $ivlen);
        return openssl_decrypt($encrypted, $method, $key, 0, $iv);
    }
    
    /**
     * Sanitize and encrypt API key
     */
    public static function sanitize_api_key($value) {
        $value = sanitize_text_field($value);
        if (!empty($value)) {
            update_option('fastpix_api_key_encrypted', self::encrypt_value($value));
        }
        return $value;
    }
    
    /**
     * Sanitize and encrypt API secret
     */
    public static function sanitize_api_secret($value) {
        $value = sanitize_text_field($value);
        if (!empty($value)) {
            update_option('fastpix_api_secret_encrypted', self::encrypt_value($value));
        }
        return $value;
    }
    
    /**
     * Get decrypted API key
     */
    public static function get_api_key() {
        $encrypted = get_option('fastpix_api_key_encrypted');
        return $encrypted ? self::decrypt_value($encrypted) : '';
    }
    
    /**
     * Get decrypted API secret
     */
    public static function get_api_secret() {
        $encrypted = get_option('fastpix_api_secret_encrypted');
        return $encrypted ? self::decrypt_value($encrypted) : '';
    }
    
    /**
     * Get API credentials array (throws exception if not configured)
     */
    public static function get_api_credentials() {
        $api_key = self::get_api_key();
        $api_secret = self::get_api_secret();

        if (empty($api_key) || empty($api_secret)) {
            throw new \Exception('API credentials are not configured. Please set your API key and secret in the FastPix settings.');
        }

        return array(
            'api_key' => $api_key,
            'api_secret' => $api_secret
        );
    }
    
    /**
     * Get upload instructions from config file
     */
    public static function get_upload_instructions() {
        $instructions_file = FASTPIX_PLUGIN_DIR . 'includes/instructions.php';
        if (file_exists($instructions_file)) {
            $instructions = include $instructions_file;
            return isset($instructions['upload_page']) ? $instructions['upload_page'] : array();
        }
        
        // Fallback if file doesn't exist
        return array(
            'title' => __('Instructions', 'fastpix'),
            'items' => array(
                __('The FastPix Video API uses a token key pair that consists of an Access Token ID and Secret Key for authentication.', 'fastpix'),
                __('Enable automatic subtitles in multiple languages or upload your own custom subtitle files (VTT, SRT).', 'fastpix'),
                __('MP4 generation can be enabled for offline viewing or downloads. Choose highest resolution or audio-only options based on your needs.', 'fastpix'),
                __('Once uploaded, copy the generated shortcode to embed videos anywhere in your WordPress site.', 'fastpix'),
                __('Monitor your video status and manage all uploaded videos from the Video List page. Videos may take a few moments to process after upload.', 'fastpix')
            )
        );
    }
    
    /**
     * Get language code to name mapping for subtitles
     */
    /** Languages the platform marks Beta (docs: Generate subtitles automatically, 2026-09-20). Labels only — never sent to the API. */
    public static function get_beta_languages() {
        return array('pl', 'ru', 'nl', 'ca', 'tr', 'sv', 'uk', 'no', 'fi', 'sk', 'el', 'cs', 'hr', 'da', 'ro', 'bg');
    }

    /** Display label: the language name, with " · Beta" where the dashboard shows it (QA U5). */
    public static function get_language_label($code) {
        $map = self::get_language_map();
        $name = isset($map[$code]) ? $map[$code] : strtoupper((string) $code);

        return in_array($code, self::get_beta_languages(), true) ? $name . ' · ' . __('Beta', 'fastpix') : $name;
    }

    public static function get_language_map() {
        // The platform's auto-subtitle list (docs: Generate subtitles automatically).
        return [
            'en' => 'English',
            'es' => 'Spanish',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'de' => 'German',
            'fr' => 'French',
            'pl' => 'Polish',
            'ru' => 'Russian',
            'nl' => 'Dutch',
            'ca' => 'Catalan',
            'tr' => 'Turkish',
            'sv' => 'Swedish',
            'uk' => 'Ukrainian',
            'no' => 'Norwegian',
            'fi' => 'Finnish',
            'sk' => 'Slovak',
            'el' => 'Greek',
            'cs' => 'Czech',
            'hr' => 'Croatian',
            'da' => 'Danish',
            'ro' => 'Romanian',
            'bg' => 'Bulgarian',
        ];
    }
    
    /**
     * Clean error message by removing WordPress prefixes
     */
    public static function clean_error_message($error_message) {
        return str_replace('wp_remote_request:', '', $error_message);
    }
    
    /**
     * Format file size for display
     */
    public static function format_file_size($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
    
    /**
     * Get language name from language code
     */
    public static function get_language_name($language_code) {
        $language_map = self::get_language_map();
        return $language_map[$language_code] ?? 'English';
    }
    
    /**
     * Get FastPix API base URL
     */
    public static function get_api_base_url() {
        return 'https://api.fastpix.com/v1';
    }
    
    /**
     * Build complete API URL with endpoint
     */
    public static function build_api_url($endpoint) {
        $base_url = self::get_api_base_url();
        return rtrim($base_url, '/') . '/' . ltrim($endpoint, '/');
    }
    
    /**
     * Get FastPix stream base URL
     */
    public static function get_stream_base_url() {
        return 'https://stream.fastpix.com';
    }
    
    /**
     * Get FastPix playback base URL
     */
    public static function get_playback_base_url() {
        return 'https://play.fastpix.com';
    }
    
    /**
     * Build stream URL for video playback
     */
    public static function build_stream_url($playback_id, $format = 'm3u8') {
        $base_url = self::get_stream_base_url();
        return rtrim($base_url, '/') . '/' . ltrim($playback_id, '/') . '.' . ltrim($format, '.');
    }
    
    /**
     * Build stream URL for MP4 download
     */
    public static function build_mp4_url($playback_id, $quality = 'capped-4k') {
        $base_url = self::get_stream_base_url();
        return rtrim($base_url, '/') . '/' . ltrim($playback_id, '/') . '/' . ltrim($quality, '/') . '.mp4';
    }
    
    /**
     * Build stream URL for audio download
     */
    public static function build_audio_url($playback_id, $format = 'm4a') {
        $base_url = self::get_stream_base_url();
        return rtrim($base_url, '/') . '/' . ltrim($playback_id, '/') . '/audio.' . ltrim($format, '.');
    }
    
    /**
     * Build playback URL for iframe embedding
     */
    public static function build_playback_url($playback_id) {
        $base_url = self::get_playback_base_url();
        return rtrim($base_url, '/') . '/?playbackId=' . urlencode($playback_id);
    }
} 