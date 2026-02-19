<?php

class PluginInstaller
{
    private WPClient $client;
    private Logger $logger;

    public function __construct(WPClient $client, Logger $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
    }

    public function install()
    {
        $this->logger->info("Installing flywp-migrator from wordpress.org");

        Retry::run(function () {
            $this->client->request(
                'POST',
                '/wp-json/wp/v2/plugins',
                ['slug' => 'flywp-migrator']
            );
        });
    }

    public function activate()
    {
        $this->logger->info("Activating plugin");

        Retry::run(function () {
            $this->client->request(
                'POST',
                '/wp-json/wp/v2/plugins/flywp-migrator/flywp-migrator',
                ['status' => 'active']
            );
        });
    }
}
