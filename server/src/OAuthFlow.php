<?php

class OAuthFlow
{
    private string $source;
    private int $port = 80;

    public function __construct(string $source)
    {
        $this->source = $source;
    }

    public function run(): array
    {
        $cmd = "php -S localhost:$this->port oauth-server.php";
        $process = proc_open($cmd, [], $pipes);

        sleep(1);

        $url = $this->source . "/wp-admin/authorize-application.php"
             . "?app_name=FlyWP+Migrator"
             . "&success_url=https://app.arm64.baasb.de:$this->port/callback";

        echo "Open this URL:\n$url\n";

        while (!file_exists("oauth-result.json")) {
            sleep(1);
        }

        $data = json_decode(file_get_contents("oauth-result.json"), true);
        unlink("oauth-result.json");

        proc_terminate($process);

        return $data;
    }
}
