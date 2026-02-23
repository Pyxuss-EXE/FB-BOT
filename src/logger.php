<?php
namespace FBBot;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

class Logger
{
    private static $instance = null;
    private $logger;

    private function __construct()
    {
        $this->logger = new MonologLogger('fb-bot');
        $logLevel = getenv('LOG_LEVEL') ?: 'debug';
        $level = constant("Monolog\\Logger::" . strtoupper($logLevel));
        $this->logger->pushHandler(new RotatingFileHandler(__DIR__ . '/../logs/activity.log', 7, $level));
        $this->logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/error.log', MonologLogger::ERROR));
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->logger;
    }
}