<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/vendor/autoload.php';

use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Request;

file_put_contents(__DIR__ . '/raw.log',
    date('[Y-m-d H:i:s] ') . file_get_contents('php://input') . "\n\n",
    FILE_APPEND
);

$bot_api_key  = '8025480052:AAEttyqG8vThN3zQMPVjzHVg3CUejaI8i08';
$bot_username = 'arsen_photo_editor_bot';

try {
    $telegram = new Telegram($bot_api_key, $bot_username);
    $telegram->addCommandsPath(__DIR__ . '/src/Commands');
    $telegram->handle();
} catch (Longman\TelegramBot\Exception\TelegramException $e) {
    file_put_contents(__DIR__ . '/error.log',
        date('[Y-m-d H:i:s] ') . $e->getMessage() . "\n",
        FILE_APPEND
    );
}
