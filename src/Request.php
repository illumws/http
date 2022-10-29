<?php

namespace illum\Http;


class Request
{
    const METHOD_HEAD = 'HEAD';
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    const METHOD_PUT = 'PUT';
    const METHOD_PATCH = 'PATCH';
    const METHOD_DELETE = 'DELETE';
    const METHOD_OPTIONS = 'OPTIONS';
    const METHOD_OVERRIDE = '_METHOD';

    /**
     * @var string|null
     */
    protected static ?string $requestUri = null;

    /**
     * @var string|null
     */
    protected static ?string $pathInfo = null;

    /**
     * @var string|null
     */
    protected static ?string $baseUrl = null;

    /**
     * @var array
     */
    protected static array $formDataMediaTypes = ['application/x-www-form-urlencoded'];

    /**
     * @return false|mixed|string
     */
    public static function prepareRequestUri(){
        $requestUri = '';

        if (isset($_SERVER['IIS_WasUrlRewritten']) and isset($_SERVER['UNENCODED_URL']) and '1' == $_SERVER['IIS_WasUrlRewritten'] && '' != $_SERVER['UNENCODED_URL']) {
            // IIS7 with URL Rewrite: make sure we get the unencoded URL (double slash problem)
            $requestUri = $_SERVER['UNENCODED_URL'];
            unset($_SERVER['UNENCODED_URL']);
            unset($_SERVER['IIS_WasUrlRewritten']);
        } elseif (isset($_SERVER['REQUEST_URI'])) {
            $requestUri = $_SERVER['REQUEST_URI'];

            if ('' !== $requestUri && '/' === $requestUri[0]) {
                // To only use path and query remove the fragment.
                if (false !== $pos = strpos($requestUri, '#')) {
                    $requestUri = substr($requestUri, 0, $pos);
                }
            } else {
                // HTTP proxy reqs setup request URI with scheme and host [and port] + the URL path,
                // only use URL path.
                $uriComponents = parse_url($requestUri);

                if (isset($uriComponents['path'])) {
                    $requestUri = $uriComponents['path'];
                }

                if (isset($uriComponents['query'])) {
                    $requestUri .= '?'.$uriComponents['query'];
                }
            }
        } elseif (isset($_SERVER['ORIG_PATH_INFO'])) {
            // IIS 5.0, PHP as CGI
            $requestUri = $_SERVER['ORIG_PATH_INFO'];
            if ('' != $_SERVER['QUERY_STRING']) {
                $requestUri .= '?'.$_SERVER['QUERY_STRING'];
            }
            unset($_SERVER['ORIG_PATH_INFO']);
        }

        // normalize the request URI to ease creating sub-requests from this request
        $_SERVER['REQUEST_URI'] = $requestUri;

        return $requestUri;
    }

    /**
     * @return string
     */
    public static function getRequestUri(): string
    {
        if (null === static::$requestUri) {
            static::$requestUri = static::prepareRequestUri();
        }

        return static::$requestUri;
    }

    /**
     * @return false|string
     */
    public static function preparePathInfo(){

        if (null === ($requestUri = static::getRequestUri())) {
            return '/';
        }

        // Remove the query string from REQUEST_URI
        if (false !== $pos = strpos($requestUri, '?')) {
            $requestUri = substr($requestUri, 0, $pos);
        }
        if ('' !== $requestUri && '/' !== $requestUri[0]) {
            $requestUri = '/'.$requestUri;
        }

        if (null === ($baseUrl = static::getBaseUrlReal())) {
            return $requestUri;
        }

        $pathInfo = substr($requestUri, \strlen($baseUrl));
        if (false === $pathInfo || '' === $pathInfo) {
            // If substr() returns false then PATH_INFO is set to an empty string
            return '/';
        }

        return $pathInfo;
    }

    /**
     * @return string
     */
    public static function getPathInfo(): string
    {
        if (null === static::$pathInfo) {
            static::$pathInfo = static::preparePathInfo();
        }

        return static::$pathInfo;
    }

    /**
     * Prepares the base URL.
     */
    protected static function prepareBaseUrl(): string
    {
        $filename = basename($_SERVER['SCRIPT_FILENAME'] ?? '');

        if (basename($_SERVER['SCRIPT_NAME'] ?? '') === $filename) {
            $baseUrl = $_SERVER['SCRIPT_NAME'];
        } elseif (basename($_SERVER['PHP_SELF'] ?? '') === $filename) {
            $baseUrl = $_SERVER['PHP_SELF'];
        } elseif (basename($_SERVER['ORIG_SCRIPT_NAME'] ?? '') === $filename) {
            $baseUrl = $_SERVER['ORIG_SCRIPT_NAME']; // 1and1 shared hosting compatibility
        } else {
            // Backtrack up the script_filename to find the portion matching
            // php_self
            $path = $_SERVER['PHP_SELF'] ?? '';
            $file = $_SERVER['SCRIPT_FILENAME'] ?? '';
            $segs = explode('/', trim($file, '/'));
            $segs = array_reverse($segs);
            $index = 0;
            $last = \count($segs);
            $baseUrl = '';
            do {
                $seg = $segs[$index];
                $baseUrl = '/'.$seg.$baseUrl;
                ++$index;
            } while ($last > $index && (false !== $pos = strpos($path, $baseUrl)) && 0 != $pos);
        }

        // Does the baseUrl have anything in common with the request_uri?
        $requestUri = static::getRequestUri();
        if ('' !== $requestUri && '/' !== $requestUri[0]) {
            $requestUri = '/'.$requestUri;
        }

        if ($baseUrl && null !== $prefix = static::getUrlencodedPrefix($requestUri, $baseUrl)) {
            // full $baseUrl matches
            return $prefix;
        }

        if ($baseUrl && null !== $prefix = static::getUrlencodedPrefix($requestUri, rtrim(\dirname($baseUrl), '/'.\DIRECTORY_SEPARATOR).'/')) {
            // directory portion of $baseUrl matches
            return rtrim($prefix, '/'.\DIRECTORY_SEPARATOR);
        }

        $truncatedRequestUri = $requestUri;
        if (false !== $pos = strpos($requestUri, '?')) {
            $truncatedRequestUri = substr($requestUri, 0, $pos);
        }

        $basename = basename($baseUrl ?? '');
        if (empty($basename) || !strpos(rawurldecode($truncatedRequestUri), $basename)) {
            // no match whatsoever; set it blank
            return '';
        }

        // If using mod_rewrite or ISAPI_Rewrite strip the script filename
        // out of baseUrl. $pos !== 0 makes sure it is not matching a value
        // from PATH_INFO or QUERY_STRING
        if (\strlen($requestUri) >= \strlen($baseUrl) && (false !== $pos = strpos($requestUri, $baseUrl)) && 0 !== $pos) {
            $baseUrl = substr($requestUri, 0, $pos + \strlen($baseUrl));
        }

        return rtrim($baseUrl, '/'.\DIRECTORY_SEPARATOR);
    }

    /**
     * @return string
     */
    private static function getBaseUrlReal(): string
    {
        if (null === static::$baseUrl) {
            static::$baseUrl = static::prepareBaseUrl();
        }

        return static::$baseUrl;
    }

    /**
     * @param string $string
     * @param string $prefix
     * @return string|null
     */
    private static function getUrlencodedPrefix(string $string, string $prefix): ?string
    {
        if (!str_starts_with(rawurldecode($string), $prefix)) {
            return null;
        }

        $len = \strlen($prefix);

        if (preg_match(sprintf('#^(%%[[:xdigit:]]{2}|.){%d}#', $len), $string, $match)) {
            return $match[0];
        }

        return null;
    }

    /**
     * Get HTTP method
     * @return string
     */
    public static function getMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'];
    }

    /**
     * Check for request method type
     *
     * @param string $type The type of request to check for
     * @return bool
     */
    public static function typeIs(string $type): bool
    {
        return static::getMethod() === strtoupper($type);
    }

    /**
     * Find if request has a particular header
     *
     * @param string $header Header to check for
     * @return bool
     */
    public static function hasHeader(string $header): bool
    {
        return !!Headers::get($header);
    }

    /**
     * Is this an AJAX request?
     * @return bool
     */
    public static function isAjax(): bool
    {
        if (static::params('isajax')) {
            return true;
        }

        if (Headers::get('X_REQUESTED_WITH') && Headers::get('X_REQUESTED_WITH') === 'XMLHttpRequest') {
            return true;
        }

        return false;
    }

    /**
     * Is this an XHR request? (alias of Leaf_Http_Request::isAjax)
     * @return bool
     */
    public static function isXhr(): bool
    {
        return static::isAjax();
    }

    /**
     * Access stream that allows you to read raw data from the request body. **This is not for form data**
     *
     * @param boolean $safeData Sanitize data?
     */
    public static function input($safeData = true)
    {
        $handler = fopen('php://input', 'r');
        $data = stream_get_contents($handler);

        if (Headers::get('Content-Type') === 'application/x-www-form-urlencoded') {
            $d = $data;
            $data = [];

            foreach (explode('&', $d) as $chunk) {
                $param = explode('=', $chunk);
                $data[$param[0]] = $param[1];
            }
        } else if (strpos(Headers::get('Content-Type') ?? '', 'application/json') !== 0 && strpos(Headers::get('Content-Type'), 'multipart/form-data') !== 0) {
            $safeData = false;
            $data = [$data];
        } else {
            if (!$data) {
                $data = json_encode([]);
            }

            $parsedData = json_decode($data, true);
            $data = is_array($parsedData) ? $parsedData : [$parsedData];
        }

        return $safeData ? \Leaf\Anchor::sanitize($data) : $data;
    }

    /**
     * Fetch GET and POST data
     *
     * This method returns a union of GET and POST data as a key-value array, or the value
     * of the array key if requested. If the array key does not exist, NULL is returned,
     * unless there is a default value specified.
     *
     * @param string|null $key
     * @param mixed|null $default
     *
     * @return mixed
     */
    public static function params(string $key = null, $default = null)
    {
        $union = static::body();

        if ($key) {
            return $union[$key] ?? $default;
        }

        return $union;
    }

    /**
     * Attempt to retrieve data from the request.
     *
     * Data which is not found in the request parameters will
     * be completely removed instead of returning null. Use `get`
     * if you want to return null or `params` if you want to set
     * a default value.
     *
     * @param array $params The parameters to return
     * @param bool $safeData Sanitize output?
     * @param bool $noEmptyString Remove empty strings from return data?
     */
    public static function try(array $params, bool $safeData = true, bool $noEmptyString = false)
    {
        $data = static::get($params, $safeData);
        $dataKeys = array_keys($data);

        foreach ($dataKeys as $key) {
            if (!isset($data[$key])) {
                unset($data[$key]);
                continue;
            }

            if ($noEmptyString && !strlen($data[$key])) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Get raw request data
     *
     * @param string|array $item The items to output
     * @param mixed $default The default value to return if no data is available
     */
    public static function rawData($item = null, $default = null)
    {
        return \Leaf\Anchor::deepGet(static::input(false), $item) ?? $default;
    }

    /**
     * Return only get request data
     *
     * @param string|array $item The items to output
     * @param mixed $default The default value to return if no data is available
     */
    public static function urlData($item = null, $default = null)
    {
        return \Leaf\Anchor::deepGet($_GET, $item) ?? $default;
    }

    /**
     * Return only get request data
     *
     * @param string|array $item The items to output
     * @param mixed $default The default value to return if no data is available
     */
    public static function postData($item = null, $default = null)
    {
        return \Leaf\Anchor::deepGet($_POST, $item) ?? $default;
    }

    /**
     * Returns request data
     *
     * This methods returns data passed into the request (request or form data).
     * This method returns get, post, put patch, delete or raw faw form data or NULL
     * if the data isn't found.
     *
     * @param array|string $params The parameter(s) to return
     * @param bool $safeData Sanitize output
     */
    public static function get($params, bool $safeData = true)
    {
        if (is_string($params)) {
            return static::body($safeData)[$params] ?? null;
        }

        $data = [];

        foreach ($params as $param) {
            $data[$param] = static::get($param, $safeData);
        }

        return $data;
    }

    /**
     * @param bool $safeData
     * @return array
     */
    public static function all(bool $safeData = false): array
    {
        return static::body($safeData);
    }

    /**
     * Get all the request data as an associative array
     *
     * @param bool $safeData Sanitize output
     */
    public static function body(bool $safeData = true)
    {
        $finalData = array_merge(static::urlData(), $_FILES, static::postData(), static::input());

        return $safeData ?
            \Leaf\Anchor::sanitize($finalData) :
            $finalData;
    }

    /**
     * Get all files passed into the request.
     *
     * @param array|string|null $filenames The file(s) you want to get
     */
    public static function files($filenames = null)
    {
        if ($filenames == null) {
            return $_FILES;
        }

        if (is_string($filenames)) {
            return $_FILES[$filenames] ?? null;
        }

        $files = [];
        foreach ($filenames as $filename) {
            $files[$filename] = $_FILES[$filename] ?? null;
        }
        return $files;
    }

    /**
     * Fetch COOKIE data
     *
     * This method returns a key-value array of Cookie data sent in the HTTP request, or
     * the value of a array key if requested; if the array key does not exist, NULL is returned.
     *
     * @param string|null $key
     * @return array|string|null
     */
    public static function cookies(string $key = null)
    {
        return $key === null ?
            Cookie::all() :
            Cookie::get($key);
    }

    /**
     * Does the Request body contain parsed form data?
     * @return bool
     */
    public static function isFormData(): bool
    {
        $method = static::getMethod();

        return ($method === self::METHOD_POST && is_null(static::getContentType())) || in_array(static::getMediaType(), self::$formDataMediaTypes);
    }

    /**
     * Get Headers
     *
     * This method returns a key-value array of headers sent in the HTTP request, or
     * the value of a hash key if requested; if the array key does not exist, NULL is returned.
     *
     * @param array|string|null $key The header(s) to return
     * @param bool $safeData Attempt to sanitize headers
     *
     * @return array|string|null
     */
    public static function headers($key = null, bool $safeData = true)
    {
        return ($key === null) ?
            Headers::all($safeData) :
            Headers::get($key, $safeData);
    }

    /**
     * Get Content Type
     * @return string|null
     */
    public static function getContentType(): ?string
    {
        return Headers::get('CONTENT_TYPE');
    }

    /**
     * Get Media Type (type/subtype within Content Type header)
     * @return string|null
     */
    public static function getMediaType(): ?string
    {
        $contentType = static::getContentType();
        if ($contentType) {
            $contentTypeParts = preg_split('/\s*[;,]\s*/', $contentType);

            return strtolower($contentTypeParts[0]);
        }

        return null;
    }

    /**
     * Get Media Type Params
     * @return array
     */
    public static function getMediaTypeParams(): array
    {
        $contentType = static::getContentType();
        $contentTypeParams = [];

        if ($contentType) {
            $contentTypeParts = preg_split('/\s*[;,]\s*/', $contentType);
            $contentTypePartsLength = count($contentTypeParts);

            for ($i = 1; $i < $contentTypePartsLength; $i++) {
                $paramParts = explode('=', $contentTypeParts[$i]);
                $contentTypeParams[strtolower($paramParts[0])] = $paramParts[1];
            }
        }

        return $contentTypeParams;
    }

    /**
     * Get Content Charset
     * @return string|null
     */
    public static function getContentCharset(): ?string
    {
        $mediaTypeParams = static::getMediaTypeParams();
        if (isset($mediaTypeParams['charset'])) {
            return $mediaTypeParams['charset'];
        }

        return null;
    }

    /**
     * Get Content-Length
     * @return int
     */
    public static function getContentLength(): int
    {
        return Headers::get('CONTENT_LENGTH') ?? 0;
    }

    /**
     * Get Host
     * @return string
     */
    public static function getHost(): string
    {
        if (isset($_SERVER['HTTP_HOST'])) {
            if (preg_match('/^(\[[a-fA-F0-9:.]+])(:\d+)?\z/', $_SERVER['HTTP_HOST'], $matches)) {
                return $matches[1];
            } else if (strpos($_SERVER['HTTP_HOST'], ':') !== false) {
                $hostParts = explode(':', $_SERVER['HTTP_HOST']);

                return $hostParts[0];
            }

            return $_SERVER['HTTP_HOST'];
        }

        return $_SERVER['SERVER_NAME'];
    }

    /**
     * Get Host with Port
     * @return string
     */
    public static function getHostWithPort(): string
    {
        return sprintf('%s:%s', static::getHost(), static::getPort());
    }

    /**
     * Get Port
     * @return int
     */
    public static function getPort(): int
    {
        return (int)$_SERVER['SERVER_PORT'] ?? 80;
    }

    /**
     * Get Scheme (https or http)
     * @return string
     */
    public static function getScheme(): string
    {
        return empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off' ? 'http' : 'https';
    }

    /**
     * Get Script Name (physical path)
     * @return string
     */
    public static function getScriptName(): string
    {
        return $_SERVER['SCRIPT_NAME'];
    }

    /**
     * Get Path (physical path + virtual path)
     * @return string
     */
    public static function getPath(): string
    {
        return static::getScriptName() . static::getPathInfo();
    }

    /**
     * Get URL (scheme + host [ + port if non-standard ])
     * @return string
     */
    public static function getUrl(): string
    {
        $url = static::getScheme() . '://' . static::getHost();

        if ((static::getScheme() === 'https' && static::getPort() !== 443) || (static::getScheme() === 'http' && static::getPort() !== 80)) {
            $url .= ':' . static::getPort();
        }

        return $url;
    }

    /**
     * Get IP
     * @return string
     */
    public static function getIp(): string
    {
        $keys = ['X_FORWARDED_FOR', 'HTTP_X_FORWARDED_FOR', 'CLIENT_IP', 'REMOTE_ADDR'];

        foreach ($keys as $key) {
            if (isset($_SERVER[$key])) {
                return $_SERVER[$key];
            }
        }

        return $_SERVER['REMOTE_ADDR'];
    }

    /**
     * Get Referrer
     * @return string|null
     */
    public static function getReferrer(): ?string
    {
        return Headers::get('HTTP_REFERER');
    }

    /**
     * Get Referer (for those who can't spell)
     * @return string|null
     */
    public static function getReferer(): ?string
    {
        return static::getReferrer();
    }

    /**
     * Get User Agent
     * @return string|null
     */
    public static function getUserAgent(): ?string
    {
        return Headers::get('HTTP_USER_AGENT');
    }
}
