<?php

namespace App\Helpers;

/**
 * HTTP response object
 */
class HttpResponse
{
    public function __construct(
        public readonly string $body,
        public readonly array $headers,
        public readonly int $statusCode,
        public readonly string $statusText = '',
        public readonly array $info = []
    ) {}

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function getHeader(string $name): ?string
    {
        $name = strtolower($name);
        foreach ($this->headers as $header => $value) {
            if (strtolower($header) === $name) {
                return $value;
            }
        }
        return null;
    }
}

class Http
{
    const USER_AGENT = 'MChef/1.0';

    /**
     * Make a GET request
     */
    public static function get(string $url, array $headers = [], array $options = []): HttpResponse
    {
        return self::request('GET', $url, null, $headers, $options);
    }

    /**
     * Make a POST request
     */
    public static function post(string $url, $data = null, array $headers = [], array $options = []): HttpResponse
    {
        return self::request('POST', $url, $data, $headers, $options);
    }

    /**
     * Make a PUT request
     */
    public static function put(string $url, $data = null, array $headers = [], array $options = []): HttpResponse
    {
        return self::request('PUT', $url, $data, $headers, $options);
    }

    /**
     * Make a DELETE request
     */
    public static function delete(string $url, array $headers = [], array $options = []): HttpResponse
    {
        return self::request('DELETE', $url, null, $headers, $options);
    }

    /**
     * Make an HTTP request using cURL
     */
    public static function request(string $method, string $url, $data = null, array $headers = [], array $options = []): HttpResponse
    {
        // Make all headers lowercase for consistency.
        $headers = array_change_key_case($headers, CASE_LOWER);
        if (!isset($headers['accept'])) {
            $headers['accept'] = '*/*';
        }
        if (!isset($headers['user-agent'])) {
            $headers['user-agent'] = self::USER_AGENT;
        }
        $ch = curl_init();

        // Default cURL options
        $defaultOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_HEADERFUNCTION => null, // Will be set below
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        // Method-specific options
        switch (strtoupper($method)) {
            case 'POST':
                $defaultOptions[CURLOPT_POST] = true;
                if ($data !== null) {
                    $defaultOptions[CURLOPT_POSTFIELDS] = is_array($data) ? http_build_query($data) : $data;
                }
                break;
            case 'PUT':
                $defaultOptions[CURLOPT_CUSTOMREQUEST] = 'PUT';
                if ($data !== null) {
                    $defaultOptions[CURLOPT_POSTFIELDS] = is_array($data) ? http_build_query($data) : $data;
                }
                break;
            case 'DELETE':
                $defaultOptions[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                break;
            case 'GET':
            default:
                // GET is the default
                break;
        }

        // Convert headers array to cURL format
        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                // Header already in "Name: Value" format
                $curlHeaders[] = $value;
            } else {
                // Convert associative array to "Name: Value" format
                $curlHeaders[] = "{$name}: {$value}";
            }
        }
        if (!empty($curlHeaders)) {
            $defaultOptions[CURLOPT_HTTPHEADER] = $curlHeaders;
        }

        // Merge user options with defaults (user options take precedence)
        $curlOptions = array_replace($defaultOptions, $options);

        // Capture response headers
        $responseHeaders = [];
        $curlOptions[CURLOPT_HEADERFUNCTION] = function($ch, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            if (count($header) < 2) {
                return $len; // Ignore invalid headers
            }

            $name = strtolower(trim($header[0]));
            $value = trim($header[1]);
            $responseHeaders[$name] = $value;

            return $len;
        };

        curl_setopt_array($ch, $curlOptions);

        $body = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $info = curl_getinfo($ch);
        $error = curl_error($ch);

        if ($body === false) {
            throw new \RuntimeException("HTTP request failed: url - {$url} error - {$error}");
        }

        // Extract status text from HTTP response line if available
        $statusText = '';
        if (isset($info['http_code'])) {
            $statusTexts = [
                200 => 'OK',
                201 => 'Created',
                204 => 'No Content',
                400 => 'Bad Request',
                401 => 'Unauthorized',
                403 => 'Forbidden',
                404 => 'Not Found',
                429 => 'Too Many Requests',
                500 => 'Internal Server Error',
                502 => 'Bad Gateway',
                503 => 'Service Unavailable',
            ];
            $statusText = $statusTexts[$statusCode] ?? '';
        }

        return new HttpResponse($body, $responseHeaders, $statusCode, $statusText, $info);
    }

    /**
     * File get contents wrapper to handle PHP version differences in fetching headers after a request.
     */
    private static function fileGetContents(string $url, array $options): array {
        if (!function_exists('http_get_last_response_headers')) {
            require_once __DIR__.'/lib/PrePHP84Http.php';
        } else {
            require_once __DIR__.'/lib/PHP84Http.php';
        }

        return mchef_fetch_content($url, $options);
    }

    /**
     * Alternative method using fileGetContents with stream context
     * Useful when cURL is not available
     */
    public static function getWithStream(string $url, array $headers = [], array $options = []): HttpResponse
    {
        // Build headers for stream context
        $headerStrings = [];
        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                $headerStrings[] = $value;
            } else {
                $headerStrings[] = "{$name}: {$value}";
            }
        }

        // Default stream context options
        $contextOptions = [
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headerStrings),
                'timeout' => $options['timeout'] ?? 30,
                'ignore_errors' => true,
                'user_agent' => self::USER_AGENT,
            ]
        ];

        // Merge with user options
        if (isset($options['context'])) {
            $contextOptions = array_merge_recursive($contextOptions, $options['context']);
        }

        [$body, $headerLines] = self::fileGetContents($url, $contextOptions);

        $responseHeaders = [];

        if (empty($headerLines)) {
            // If no $http_response_header, it means the request completely failed
            throw new \RuntimeException("No response headers received - network error for URL: {$url}");
        }
        
        foreach ($headerLines as $line) {
            if (strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
        }

        // Extract status code from first header line
        $statusCode = 200; // Default fallback
        if (isset($headerLines[0]) && preg_match('/HTTP\/\S+\s(\d{3})/', $headerLines[0], $matches)) {
            $statusCode = (int)$matches[1];
        }

        return new HttpResponse($body, $responseHeaders, $statusCode);
    }
}