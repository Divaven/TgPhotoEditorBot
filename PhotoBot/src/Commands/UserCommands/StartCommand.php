<?php
namespace Longman\TelegramBot\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Request;

class StartCommand extends UserCommand
{
    protected $name        = 'start';
    protected $description = 'Стартовое приветствие';
    protected $usage       = '/start';
    protected $version     = '1.0.0';

    public function execute(): \Longman\TelegramBot\Entities\ServerResponse
    {
        return Request::sendMessage([
            'chat_id' => $this->getMessage()->getChat()->getId(),
            'text'    => '👋 Привет! Пришли мне любое фото, я его обработаю.',
        ]);
    }
}
