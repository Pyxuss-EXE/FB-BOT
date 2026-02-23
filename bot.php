#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';
use FBBot\TelegramBot;
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
date_default_timezone_set('UTC');
$bot = new TelegramBot();
$bot->run();