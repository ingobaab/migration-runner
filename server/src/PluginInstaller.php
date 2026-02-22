<?php

class PluginInstaller
{
    private WPClient $client;
    private Logger $logger;

    private string $slug = 'flywp-migrator';
    private string $plugin = 'flywp-migrator/flywp-migrator';

    public function __construct(WPClient $client, Logger $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
    }

    public function installOrUpdateAndActivate(): void
    {
        if ($this->isInstalled()) {
            $this->logger->info("Plugin already installed – updating");

            $this->update();
        } else {
            $this->logger->info("Plugin not installed – installing");

            $this->install();
        }

        $this->activate();
    }

    private function isInstalled(): bool
    {
        try {
            $this->client->request(
                'GET',
                "/wp-json/wp/v2/plugins/{$this->plugin}"
            );

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function install(): void
    {
        Retry::run(function () {
            $this->client->request(
                'POST',
                '/wp-json/wp/v2/plugins',
                ['slug' => $this->slug]
            );
        });
    }

    private function update(): void
    {
        Retry::run(function () {
            $this->client->request(
                'POST',
                "/wp-json/wp/v2/plugins/{$this->plugin}",
                ['update' => true]
            );
        });
    }

    private function activate(): void
    {
        Retry::run(function () {
            $this->client->request(
                'POST',
                "/wp-json/wp/v2/plugins/{$this->plugin}",
                ['status' => 'active']
            );
        });
    }
}