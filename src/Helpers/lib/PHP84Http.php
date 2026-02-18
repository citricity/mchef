<?php

// Deliberately no namespace here as this is included directly in Http.php for PHP 8.4 and above support

/**
 * PHP 8.4+ implementation of content fetching using file_get_contents
 * This is used for PHP 8.4 and above where http_get_last_response_headers() is available to get headers after a request
 */
function mchef_fetch_content(string $url, ?array $options = null): array {
    $context = stream_context_create($options);
    $body = @file_get_contents($url, false, $context);

    if ($body === false) {
        $error = error_get_last();
        throw new \RuntimeException("HTTP request failed: url - {$url} error - " . ($error['message'] ?? 'Unknown error'));
    }
    $headers = http_get_last_response_headers() ?? [];

    return [$body, $headers];
}