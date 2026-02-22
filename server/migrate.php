<?php

require __DIR__ . '/src/Logger.php';
require __DIR__ . '/src/Retry.php';
require __DIR__ . '/src/OAuthFlow.php';
require __DIR__ . '/src/WPClient.php';
require __DIR__ . '/src/PluginInstaller.php';
require __DIR__ . '/src/MigrationPuller.php';

$options = getopt("", ["source:"]);

if (!isset($options['source'])) {
    exit("Usage: php migrate.php --source=https://example.com\n");
}

$logger = new Logger();

$source = rtrim($options['source'], '/');

$logger->info("Starting OAuth");

$oauth = new OAuthFlow($source);
$creds = $oauth->run();

$logger->info("OAuth successful");

$client = new WPClient(
    $creds['site_url'],
    $creds['user_login'],
    $creds['password'],
    $logger
);

$installer = new PluginInstaller($client, $logger);
$installer->installOrUpdateAndActivate();

$puller = new MigrationPuller($client, $logger);

$puller->pullDatabase();
$puller->pullFiles();

$logger->info("Migration pull completed successfully.");
