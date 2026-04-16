<?php

/**
 * Legacy access to the predefined $http_response_header variable after file_get_contents()
 * with the http(s) stream wrapper. Loaded only on PHP &lt; 8.5 (see ZeroBounce callers).
 *
 * @return array<int, string>
 */
return (isset($http_response_header) && is_array($http_response_header)) ? $http_response_header : [];
