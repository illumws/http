<?php



if (!function_exists('request')) {
    /**
     * Return request or request data
     *
     * @param array|string $data — Get data from request
     * 
     * @return \illum\Http\Request
     */
    function request(): ?\illum\Http\Request
    {
        if (class_exists('\Leaf\Config')) {
            $request = Leaf\Config::get("request")["instance"] ?? null;

            if (!$request) {
                $request = new \illum\Http\Request;
                Leaf\Config::set("request", ["instance" => $request]);
            }

            return $request;
        }

        return new \illum\Http\Request();
    }
}

if (!function_exists('response')) {
    /**
     * Return response or set response data
     *
     * @param array|string $data — The JSON response to set
     * 
     * @return \illum\Http\Response
     */
    function response()
    {
        if (class_exists('\Leaf\Config')) {
            $response = Leaf\Config::get("response")["instance"] ?? null;

            if (!$response) {
                $response = new \illum\Http\Response;
                Leaf\Config::set("response", ["instance" => $response]);
            }

            return $response;
        }

        return new \illum\Http\Response();
    }
}
