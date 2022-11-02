<?php

namespace Illum\Http;

use Leaf\Anchor;

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
     * @var array
     */
    protected array $formDataMediaTypes = ['application/x-www-form-urlencoded'];

    /**
     * Get HTTP method
     * @return string
     */
    public function getMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'];
    }

    /**
     * Check for request method type
     *
     * @param string $type The type of request to check for
     * @return bool
     */
    public function typeIs(string $type): bool
    {
        return $this->getMethod() === strtoupper($type);
    }

    /**
     * Find if request has a particular header
     *
     * @param string $header  Header to check for
     * @return bool
     */
    public function hasHeader(String $header): bool
    {
        return !!Headers::get($header);
    }

    /**
     * Is this an AJAX request?
     * @return bool
     */
    public function isAjax(): bool
    {
        if ($this->params('is-ajax')) {
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
    public function isXhr(): bool
    {
        return $this->isAjax();
    }

    /**
     * Access stream that allows you to read raw data from the request body. **This is not for form data**
     *
     * @param boolean $safeData Sanitize data?
     */
    public function input($safeData = true)
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

        return $safeData ? Anchor::sanitize($data) : $data;
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
    public function params(string $key = null, $default = null)
    {
        $union = $this->body();

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
    public function try(array $params, bool $safeData = true, bool $noEmptyString = false)
    {
        $data = $this->get($params, $safeData);
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
    public function rawData($item = null, $default = null)
    {
        return Anchor::deepGet($this->input(false), $item) ?? $default;
    }

    /**
     * Return only get request data
     *
     * @param string|array $item The items to output
     * @param mixed $default The default value to return if no data is available
     */
    public function urlData($item = null, $default = null)
    {
        return Anchor::deepGet($_GET, $item) ?? $default;
    }

    /**
     * Return only get request data
     *
     * @param string|array $item The items to output
     * @param mixed $default The default value to return if no data is available
     */
    public function postData($item = null, $default = null)
    {
        return Anchor::deepGet($_POST, $item) ?? $default;
    }

    /**
     * Returns request data
     *
     * These methods return data passed into the request (request or form data).
     * This method returns get, post, put patch, delete or raw faw form data or NULL
     * if the data isn't found.
     *
     * @param array|string $params The parameter(s) to return
     * @param bool $safeData Sanitize output
     */
    public function get($params, bool $safeData = true)
    {
        if (is_string($params)) {
            return $this->body($safeData)[$params] ?? null;
        }

        $data = [];

        foreach ($params as $param) {
            $data[$param] = $this->get($param, $safeData);
        }

        return $data;
    }

    /**
     * @param bool $safeData
     * @return array
     */
    public function all(bool $safeData = false): array
    {
        return $this->body($safeData);
    }

    /**
     * Get all the request data as an associative array
     *
     * @param bool $safeData Sanitize output
     */
    public function body(bool $safeData = true)
    {
        $finalData = array_merge($this->urlData(), $_FILES, $this->postData(), $this->input());

        return $safeData ?
            Anchor::sanitize($finalData) :
            $finalData;
    }

    /**
     * Get all files passed into the request.
     *
     * @param array|string|null $filenames The file(s) you want to get
     */
    public function files($filenames = null)
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
     * the value of an array key if requested; is the array key does not exist, NULL is returned.
     *
     * @param string|null $key
     * @return array|string|null
     */
    public function cookies(string $key = null)
    {
        return $key === null ?
            Cookie::all() :
            Cookie::get($key);
    }

    /**
     * Does the Request body contain parsed form data?
     * @return bool
     */
    public function isFormData(): bool
    {
        $method = $this->getMethod();

        return ($method === self::METHOD_POST && is_null($this->getContentType())) || in_array($this->getMediaType(), $this->formDataMediaTypes);
    }

    /**
     * Get Headers
     *
     * This method returns a key-value array of headers sent in the HTTP request, or
     * the value of a hash key if requested; is the array key does not exist, NULL is returned.
     *
     * @param array|string|null $key The header(s) to return
     * @param bool $safeData Attempt to sanitize headers
     *
     * @return array|string|null
     */
    public function headers($key = null, bool $safeData = true)
    {
        return ($key === null) ?
            Headers::all($safeData) :
            Headers::get($key, $safeData);
    }

    /**
     * Get Content Type
     * @return string|null
     */
    public function getContentType(): ?string
    {
        return Headers::get('CONTENT_TYPE');
    }

    /**
     * Get Media Type (type/subtype within Content Type header)
     * @return string|null
     */
    public function getMediaType(): ?string
    {
        $contentType = $this->getContentType();
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
    public function getMediaTypeParams(): array
    {
        $contentType = $this->getContentType();
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
    public function getContentCharset(): ?string
    {
        $mediaTypeParams = $this->getMediaTypeParams();
        if (isset($mediaTypeParams['charset'])) {
            return $mediaTypeParams['charset'];
        }

        return null;
    }

    /**
     * Get Content-Length
     * @return int
     */
    public function getContentLength(): int
    {
        return Headers::get('CONTENT_LENGTH') ?? 0;
    }

    /**
     * Get Host
     * @return string
     */
    public function getHost(): string
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
    public function getHostWithPort(): string
    {
        return sprintf('%s:%s', $this->getHost(), $this->getPort());
    }

    /**
     * Get Port
     * @return int
     */
    public function getPort(): int
    {
        return (int) $_SERVER['SERVER_PORT'] ?? 80;
    }

    /**
     * Get Scheme (https or http)
     * @return string
     */
    public function getScheme(): string
    {
        return empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off' ? 'http' : 'https';
    }

    /**
     * Get Script Name (physical path)
     * @return string
     */
    public function getScriptName(): string
    {
        return $_SERVER['SCRIPT_NAME'];
    }

    /**
     * Get Path (physical path + virtual path)
     * @return string
     */
    public function getPath(): string
    {
        return $this->getScriptName() . $this->getPathInfo();
    }

    /**
     * Get Path Info (virtual path)
     * @return string|null
     */
    public function getPathInfo(): ?string
    {
        return $_SERVER['REQUEST_URI'] ?? null;
    }

    /**
     * Get URL (scheme + host [ + port if non-standard ])
     * @return string
     */
    public function getUrl(): string
    {
        $url = $this->getScheme() . '://' . $this->getHost();

        if (($this->getScheme() === 'https' && $this->getPort() !== 443) || ($this->getScheme() === 'http' && $this->getPort() !== 80)) {
            $url .= ':' . $this->getPort();
        }

        return $url;
    }

    /**
     * Get IP
     * @return string
     */
    public function getIp(): string
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
    public function getReferrer(): ?string
    {
        return Headers::get('HTTP_REFERER');
    }

    /**
     * Get Referer (for those who can't spell)
     * @return string|null
     */
    public function getReferer(): ?string
    {
        return $this->getReferrer();
    }

    /**
     * Get User Agent
     * @return string|null
     */
    public function getUserAgent(): ?string
    {
        return Headers::get('HTTP_USER_AGENT');
    }
}