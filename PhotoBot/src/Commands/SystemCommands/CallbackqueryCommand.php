<?php
namespace Longman\TelegramBot\Commands\SystemCommands;

use Longman\TelegramBot\Commands\SystemCommand;
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

        $src = $load($in);

        $ratio = max($w / $ow, $h / $oh);
        $tw    = (int) round($w  / $ratio);
        $th    = (int) round($h  / $ratio);
        $sx    = (int) (($ow - $tw) / 2);
        $sy    = (int) (($oh - $th) / 2);

        $dst = imagecreatetruecolor($w, $h);
        imagecopyresampled($dst, $src,
            0, 0,
            $sx, $sy,
            $w, $h,
            $tw, $th
        );

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

        [$w, $h, $type] = getimagesize($in);
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

    private function doCrop(string $file, int $w, int $h, int $chat): void
    {
        $out = $this->cropGd($file, $w, $h);
        Request::sendPhoto([
            'chat_id' => $chat,
            'photo'   => Request::encodeFile($out),
        ]);
    }

    private function doGray(string $file, int $chat): void
    {
        $out = $this->grayGd($file);
        Request::sendPhoto([
            'chat_id' => $chat,
            'photo'   => Request::encodeFile($out),
        ]);
    }

    private function doConvert(string $file, string $format, int $chat): void
    {
        try {
            $out = $this->convertGd($file, $format);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'tiff_gd_unsupported' && strtoupper($format) === 'TIFF') {
                $out = $this->convertViaApi($file);
            } else {
                Request::sendMessage([
                    'chat_id' => $chat,
                    'text'    => '️ Ошибка конвертации: '.$e->getMessage(),
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

        $response = Request::sendDocument([
            'chat_id'  => $chat,
            'document' => Request::encodeFile($out),
        ]);
        file_put_contents($this->tmpDir().'tg.log',
            date('[H:i:s] ').($response->isOk()? 'ok': $response->getDescription())."\n",
            FILE_APPEND
        );
    }

    public function execute(): \Longman\TelegramBot\Entities\ServerResponse
    {
        $cb      = $this->getCallbackQuery();
        $data    = $cb->getData();
        $chat_id = $cb->getMessage()->getChat()->getId();
        $msg_id  = $cb->getMessage()->getMessageId();

        $path_file = $this->tmpDir().$chat_id.'.path';
        $file = is_readable($path_file)
            ? trim(file_get_contents($path_file))
            : null;

        if (!$file || !file_exists($file)) {
            return Request::answerCallbackQuery([
                'callback_query_id' => $cb->getId(),
                'text'              => 'Нет исходного фото, пришлите заново.',
                'show_alert'        => true,
            ]);
        }

        if ($data === 'crop_menu') {
            $kbd = new \Longman\TelegramBot\Entities\InlineKeyboard(
                [['text'=>'640×480','callback_data'=>'crop_640_480']],
                [['text'=>'800×600','callback_data'=>'crop_800_600']],
                [['text'=>'1024×768','callback_data'=>'crop_1024_768']],
                [['text'=>'640×360','callback_data'=>'crop_640_360']],
            );
            return Request::editMessageText([
                'chat_id'      => $chat_id,
                'message_id'   => $msg_id,
                'text'         => 'Выберите размер:',
                'reply_markup' => $kbd,
            ]);
        }

        if ($data === 'format_menu') {
            $rows = [
                [['text'=>'PNG','callback_data'=>'format_png']],
                [['text'=>'JPG','callback_data'=>'format_jpg']],
                [['text'=>'TIFF','callback_data'=>'format_tif']],
            ];
            $kbd = new \Longman\TelegramBot\Entities\InlineKeyboard(...$rows);
            return Request::editMessageText([
                'chat_id'      => $chat_id,
                'message_id'   => $msg_id,
                'text'         => 'Выберите формат:',
                'reply_markup' => $kbd,
            ]);
        }

        if (preg_match('/^crop_(\d+)_(\d+)$/', $data, $m)) {
            $this->doCrop($file, (int)$m[1], (int)$m[2], $chat_id);
        } elseif ($data === 'grayscale') {
            $this->doGray($file, $chat_id);
        } elseif (preg_match('/^format_(png|jpg|tif)$/', $data, $m)) {
            $this->doConvert($file, strtoupper($m[1]), $chat_id);
        }

        return Request::answerCallbackQuery(['callback_query_id'=>$cb->getId()]);
    }
}
