<?php
namespace Longman\TelegramBot\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Request;

class PhotoCommand extends UserCommand
{
    protected $name        = 'photo';
    protected $description = 'Обработка фото';
    protected $version     = '1.0.1';

    public function execute(): \Longman\TelegramBot\Entities\ServerResponse
    {
        $chat_id = $this->getMessage()->getChat()->getId();
        $photo   = $this->getMessage()->getPhoto();
        $file_id = end($photo)->getFileId();

        //скачиваем файл
        $apiKey   = $this->getTelegram()->getApiKey();
        $fileInfo = Request::getFile(['file_id' => $file_id])->getResult();
        $url      = "https://api.telegram.org/file/bot{$apiKey}/{$fileInfo->getFilePath()}";

        $tmp_dir = __DIR__ . '/../../../tmp/';
        if (!is_dir($tmp_dir)) {
            mkdir($tmp_dir, 0777, true);
        }
        $local = $tmp_dir . basename($fileInfo->getFilePath());
        file_put_contents($local, fopen($url, 'r'));

        $path_file = $tmp_dir . $chat_id . '.path';
        file_put_contents($path_file, $local);

        $kbd = new InlineKeyboard(
            [['text' => 'Кадрирование', 'callback_data' => 'crop_menu']],
            [['text' => 'Ч/Б',          'callback_data' => 'grayscale']],
            [['text' => 'Конвертация',  'callback_data' => 'format_menu']]
        );

        return Request::sendMessage([
            'chat_id'      => $chat_id,
            'text'         => 'Выберите действие:',
            'reply_markup' => $kbd,
        ]);
    }
}
