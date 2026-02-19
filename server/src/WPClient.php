<?php

class WPClient
{
    private string $base;
    private string $auth;
    private Logger $logger;

    public function __construct(string $base, string $user, string $pass, Logger $logger)
    {
        $this->base = rtrim($base, '/');
        $this->auth = base64_encode($user . ':' . $pass);
        $this->logger = $logger;
    }

    public function request(string $method, string $endpoint, array $data = [])
    {
        $url = $this->base . $endpoint;

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $this->auth,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS     => empty($data) ? null : json_encode($data),
            CURLOPT_TIMEOUT        => 60
        ]);

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        curl_close($ch);

        if ($error) {
            throw new Exception("cURL error: $error");
        }

        if ($status >= 400) {
            $this->logger->error("HTTP $status on $endpoint: $response");
            throw new Exception("HTTP $status: $response");
        }

        return $response;
    }
}
