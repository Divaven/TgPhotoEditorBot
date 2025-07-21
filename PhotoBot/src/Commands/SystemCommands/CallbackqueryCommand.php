<?php
namespace Longman\TelegramBot\Commands\SystemCommands;

use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Request;

class CallbackqueryCommand extends SystemCommand
{
    protected $name    = 'callbackquery';
    protected $version = '1.0.2';

    private string $convertSecret = 'aPaEoPAuYOwAwXkWuHzUGvuJuwRC8eqi';

    private function tmpDir(): string
    {
        return __DIR__ . '/../../../tmp/';
    }

    private function cropGd(string $in, int $w, int $h): string
    {
        [$ow, $oh, $type] = getimagesize($in);
        $load = [
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG  => 'imagecreatefrompng',
            IMAGETYPE_GIF  => 'imagecreatefromgif',
        ][$type] ?? 'imagecreatefromjpeg';
        $save = [
            IMAGETYPE_JPEG => 'imagejpeg',
            IMAGETYPE_PNG  => 'imagepng',
            IMAGETYPE_GIF  => 'imagegif',
        ][$type] ?? 'imagejpeg';

        $src   = $load($in);
        $ratio = max($w / $ow, $h / $oh);
        $tw    = (int)round($w  / $ratio);
        $th    = (int)round($h  / $ratio);
        $sx    = (int)(($ow - $tw) / 2);
        $sy    = (int)(($oh - $th) / 2);

        $dst = imagecreatetruecolor($w, $h);
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $w, $h, $tw, $th);

        $out = preg_replace('/\.\w+$/', "_crop_{$w}x{$h}.jpg", $in);
        $save($dst, $out, 90);

        imagedestroy($src);
        imagedestroy($dst);

        return $out;
    }

    private function grayGd(string $in): string
    {
        [$w, $h, $type] = getimagesize($in);
        $load = [
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG  => 'imagecreatefrompng',
            IMAGETYPE_GIF  => 'imagecreatefromgif',
        ][$type] ?? 'imagecreatefromjpeg';
        $save = [
            IMAGETYPE_JPEG => 'imagejpeg',
            IMAGETYPE_PNG  => 'imagepng',
            IMAGETYPE_GIF  => 'imagegif',
        ][$type] ?? 'imagejpeg';

        $img = $load($in);
        imagefilter($img, IMG_FILTER_GRAYSCALE);

        $out = preg_replace('/\.\w+$/', '_bw.jpg', $in);
        $save($img, $out, 90);

        imagedestroy($img);
        return $out;
    }

    private function convertGd(string $in, string $format): string
    {
        $format = strtoupper($format);
        if ($format === 'TIFF') {
            throw new \RuntimeException('tiff_gd_unsupported');
        }

        [, , $type] = getimagesize($in);
        $load = [
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG  => 'imagecreatefrompng',
            IMAGETYPE_GIF  => 'imagecreatefromgif',
        ][$type] ?? 'imagecreatefromjpeg';
        $save = [
            'PNG'  => 'imagepng',
            'JPG'  => 'imagejpeg',
            'JPEG' => 'imagejpeg',
            'GIF'  => 'imagegif',
            'WEBP' => 'imagewebp',
        ][$format] ?? 'imagejpeg';

        $img = $load($in);
        $out = preg_replace('/\.\w+$/', '.'.strtolower($format), $in);
        if ($format === 'PNG') {
            $save($img, $out, 0);
        } else {
            $save($img, $out, 90);
        }
        imagedestroy($img);

        return $out;
    }

    private function convertViaApi(string $in): string
    {
        if (!$this->convertSecret) {
            throw new \RuntimeException('ConvertAPI secret not set');
        }

        $ext      = strtolower(pathinfo($in, PATHINFO_EXTENSION));
        $endpoint = "https://v2.convertapi.com/convert/{$ext}/to/tiff?Secret={$this->convertSecret}";
        $ch       = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['File' => new \CURLFile($in)],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $raw  = curl_exec($ch);
        curl_close($ch);

        file_put_contents($this->tmpDir().'convapi_raw.json', $raw."\n\n", FILE_APPEND);
        $resp = json_decode($raw, true);
        $file = $resp['Files'][0] ?? null;
        if (!$file) {
            throw new \RuntimeException('ConvertAPI error: пустой Files');
        }

        $out = preg_replace('/\.\w+$/', '.tif', $in);
        if (!empty($file['FileData'])) {
            $data = base64_decode($file['FileData']);
            if ($data === false) {
                throw new \RuntimeException('Base64 decode failed');
            }
            file_put_contents($out, $data);
        } elseif (!empty($file['Url'])) {
            $this->downloadFile($file['Url'], $out);
        } else {
            $msg = $resp['Message'] ?? 'нет FileData и нет Url';
            throw new \RuntimeException('ConvertAPI error: '.$msg);
        }

        if (!filesize($out)) {
            throw new \RuntimeException('Download failed (zero bytes)');
        }
        return $out;
    }

    private function downloadFile(string $url, string $dest): void
    {
        $ch = curl_init($url);
        $fp = fopen($dest, 'w');
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
        ]);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);

        file_put_contents($this->tmpDir().'dl.log',
            date('[H:i:s] ').basename($dest).' '.filesize($dest)." bytes\n",
            FILE_APPEND);
    }

    private function doConvert(string $file, string $format, int $chat, int $msg_id): void
    {
        try {
            $out = $this->convertGd($file, $format);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'tiff_gd_unsupported' && $format === 'TIFF') {
                $out = $this->convertViaApi($file);
            } else {
                Request::sendMessage([
                    'chat_id' => $chat,
                    'text'    => '️ Ошибка конвертации: ' . $e->getMessage(),
                ]);
                return;
            }
        }

        if (!file_exists($out) || !filesize($out)) {
            Request::sendMessage([
                'chat_id' => $chat,
                'text'    => '️ Не удалось получить готовый файл.',
            ]);
            return;
        }

        Request::editMessageReplyMarkup([
            'chat_id'      => $chat,
            'message_id'   => $msg_id,
            'reply_markup' => null,
        ]);

        Request::sendDocument([
            'chat_id'   => $chat,
            'document'  => Request::encodeFile($out),
            'caption'   => 'Ваш файл: ' . basename($out),
        ]);

        $kbd = new InlineKeyboard(
            [['text'=>'Кадрирование', 'callback_data'=>'crop_menu']],
            [['text'=>'Ч/Б',          'callback_data'=>'grayscale']],
            [['text'=>'Конвертация',  'callback_data'=>'format_menu']]
        );
        Request::sendMessage([
            'chat_id'      => $chat,
            'text'         => 'Выберите действие:',
            'reply_markup' => $kbd,
        ]);
    }

    public function execute(): \Longman\TelegramBot\Entities\ServerResponse
    {
        $cb      = $this->getCallbackQuery();
        $data    = $cb->getData();
        $chat_id = $cb->getMessage()->getChat()->getId();
        $msg_id  = $cb->getMessage()->getMessageId();

        $path_file = $this->tmpDir() . $chat_id . '.path';
        if (!is_readable($path_file) || !file_exists($path_file)) {
            return Request::answerCallbackQuery([
                'callback_query_id' => $cb->getId(),
                'text'              => 'Нет исходного фото, пришлите заново.',
                'show_alert'        => true,
            ]);
        }
        $original = trim(file_get_contents($path_file));

        if ($data === 'crop_menu') {
            $kbd = new InlineKeyboard(
                [['text'=>'640×480','callback_data'=>'crop_640_480']],
                [['text'=>'800×600','callback_data'=>'crop_800_600']],
                [['text'=>'1024×768','callback_data'=>'crop_1024_768']],
                [['text'=>'640×360','callback_data'=>'crop_640_360']]
            );
            return Request::editMessageText([
                'chat_id'      => $chat_id,
                'message_id'   => $msg_id,
                'text'         => 'Выберите размер:',
                'reply_markup' => $kbd,
            ]);
        }

        if ($data === 'format_menu') {
            $kbd = new InlineKeyboard(
                [['text'=>'PNG','callback_data'=>'format_png']],
                [['text'=>'JPG','callback_data'=>'format_jpg']],
                [['text'=>'TIFF','callback_data'=>'format_tiff']]
            );
            return Request::editMessageText([
                'chat_id'      => $chat_id,
                'message_id'   => $msg_id,
                'text'         => 'Выберите формат:',
                'reply_markup' => $kbd,
            ]);
        }

        if (preg_match('/^crop_(\d+)_(\d+)$/', $data, $m)) {
            $result = $this->cropGd($original, (int)$m[1], (int)$m[2]);
        } elseif ($data === 'grayscale') {
            $result = $this->grayGd($original);
        } elseif (preg_match('/^format_(png|jpg|tiff)$/', $data, $m)) {
            $fmt = strtoupper($m[1]) === 'TIFF' ? 'TIFF' : strtoupper($m[1]);
            $this->doConvert($original, $fmt, $chat_id, $msg_id);
            return Request::answerCallbackQuery(['callback_query_id' => $cb->getId()]);
        } else {
            return Request::answerCallbackQuery(['callback_query_id' => $cb->getId()]);
        }

        if (!empty($result) && file_exists($result)) {
            Request::editMessageReplyMarkup([
                'chat_id'      => $chat_id,
                'message_id'   => $msg_id,
                'reply_markup' => null,
            ]);
            Request::sendPhoto([
                'chat_id' => $chat_id,
                'photo'   => Request::encodeFile($result),
            ]);
            $kbd = new InlineKeyboard(
                [['text'=>'Кадрирование', 'callback_data'=>'crop_menu']],
                [['text'=>'Ч/Б',          'callback_data'=>'grayscale']],
                [['text'=>'Конвертация',  'callback_data'=>'format_menu']]
            );
            Request::sendMessage([
                'chat_id'      => $chat_id,
                'text'         => 'Выберите действие:',
                'reply_markup' => $kbd,
            ]);
        }

        return Request::answerCallbackQuery(['callback_query_id' => $cb->getId()]);
    }
}
