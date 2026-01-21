<?php

// Polyfill for PHP < 8.4
// https://www.php.net/manual/en/function.http-get-last-response-headers.php

if (!function_exists('http_get_last_response_headers')) {
    /**
     * Returns the response headers from the last HTTP request made
     * via the HTTP stream wrapper.
     *
     * @return array|null
     */
    function http_get_last_response_headers(): ?array {
        /**
         * $http_response_header is a special variable populated by
         * the HTTP stream wrapper in the local scope of the request.
         *
         * In practice it is available in the global scope immediately
         * after file_get_contents()/fopen() HTTP calls.
         */
        if (isset($GLOBALS['http_response_header']) && is_array($GLOBALS['http_response_header'])) {
            return $GLOBALS['http_response_header'];
        }

        return null;
    }
}
