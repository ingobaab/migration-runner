<?php

class MigrationPuller
{
    private WPClient $client;
    private Logger $logger;
    private string $artifactPath;

    public function __construct(WPClient $client, Logger $logger)
    {
        $this->client = $client;
        $this->logger = $logger;

        $this->artifactPath = __DIR__ . '/../artifacts/' . date('Ymd_His');
        mkdir($this->artifactPath, 0777, true);
    }

    public function pullDatabase()
    {
        $this->logger->info("Pulling tables list");

        $tables = json_decode(
            $this->client->request('GET', '/wp-json/flywp-migrator/v1/tables/structure'),
            true
        );

        $dbFile = $this->artifactPath . '/database.sql';

        foreach ($tables as $table) {

            $this->logger->info("Pulling structure: $table");

            $structure = $this->client->request(
                'GET',
                "/wp-json/flywp-migrator/v1/table/$table/structure"
            );

            file_put_contents($dbFile, $structure . PHP_EOL, FILE_APPEND);

            $offset = 0;
            $limit = 1000;

            while (true) {
                $data = $this->client->request(
                    'GET',
                    "/wp-json/flywp-migrator/v1/table/$table/data?offset=$offset&limit=$limit"
                );

                if (empty($data) || $data === '[]') break;

                file_put_contents($dbFile, $data . PHP_EOL, FILE_APPEND);

                $offset += $limit;
            }
        }
    }

    public function pullFiles()
    {
        $this->logger->info("Pulling plugins.zip");

        $plugins = $this->client->request(
            'GET',
            '/wp-json/flywp-migrator/v1/plugins/download'
        );

        file_put_contents($this->artifactPath . '/plugins.zip', $plugins);

        $this->logger->info("Pulling themes.zip");

        $themes = $this->client->request(
            'GET',
            '/wp-json/flywp-migrator/v1/themes/download'
        );

        file_put_contents($this->artifactPath . '/themes.zip', $themes);
    }
}
