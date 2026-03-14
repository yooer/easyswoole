<?php
namespace App\Utility;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use EasySwoole\EasySwoole\Config;

class Telegram 
{
    private $bot;
    private $channel;
    private $client;
    private $apiBaseUrl;

    public function __construct($channel = "grounp")
    {
        $config = Config::getInstance()->getConf('telegram');
        $this->bot = $config["botToken"];
        
        // 使用指定频道(如果存在)，否则使用默认频道
        $this->channel = isset($config["channel"][$channel]) ? 
                         $config["channel"][$channel] : 
                         $config["channel"]["grounp"];
        
        $this->client = new Client([
            'timeout' => 10.0,
            'http_errors' => false
        ]);
        $this->apiBaseUrl = "https://api.telegram.org/bot" . $this->bot;
    }

    /**
     * 发送富文本消息
     */
    public function sendText(string $text, array $inlineKeyboard = null) {
        $postData = [
            "chat_id" => $this->channel,
            "text" => $text,
            "parse_mode" => "HTML",
            "disable_web_page_preview" => false
        ];
        
        if ($inlineKeyboard) {
            $postData['reply_markup'] = [
                'inline_keyboard' => $inlineKeyboard
            ];
        }
        
        return $this->jsonPost("/sendMessage", $postData);
    }

    /**
     * 发送图片
     */
    public function sendPhoto(string $imageUrl, string $caption = '', array $inlineKeyboard = null) {
        $postData = [
            "chat_id" => $this->channel,
            "caption" => $caption,
            "parse_mode" => "HTML"
        ];
        
        if ($inlineKeyboard) {
            $postData['reply_markup'] = [
                'inline_keyboard' => $inlineKeyboard
            ];
        }
        
        if (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            $postData["photo"] = $imageUrl;
            return $this->jsonPost("/sendPhoto", $postData);
        } else {
            $multipart = [
                ['name' => 'chat_id', 'contents' => $this->channel],
                ['name' => 'caption', 'contents' => $caption],
                ['name' => 'parse_mode', 'contents' => 'HTML'],
                ['name' => 'photo', 'contents' => fopen($imageUrl, 'r'), 'filename' => basename($imageUrl)]
            ];
            
            if ($inlineKeyboard) {
                $multipart[] = [
                    'name' => 'reply_markup',
                    'contents' => json_encode(['inline_keyboard' => $inlineKeyboard])
                ];
            }
            
            return $this->multipartPost("/sendPhoto", $multipart);
        }
    }

    /**
     * 发送媒体组
     */
    public function sendMediaGroup(array $mediaItems, string $caption = '') {
        $media = [];
        $files = [];
        $fileIndex = 0;
        
        foreach ($mediaItems as $index => $item) {
            $mediaItem = [
                'type' => $item['type'] ?? 'photo',
                'media' => '',
            ];
            
            if (!empty($caption) && $index === 0) {
                $mediaItem['caption'] = $caption;
                $mediaItem['parse_mode'] = 'HTML';
            }
            
            if (isset($item['media'])) {
                if (filter_var($item['media'], FILTER_VALIDATE_URL)) {
                    $mediaItem['media'] = $item['media'];
                } else if (file_exists($item['media'])) {
                    $attachName = "file{$fileIndex}";
                    $mediaItem['media'] = "attach://{$attachName}";
                    $files[] = [
                        'name' => $attachName,
                        'contents' => fopen($item['media'], 'r'),
                        'filename' => basename($item['media'])
                    ];
                    $fileIndex++;
                }
            }
            $media[] = $mediaItem;
        }
        
        $multipart = [
            ['name' => 'chat_id', 'contents' => $this->channel],
            ['name' => 'media', 'contents' => json_encode($media)]
        ];
        
        if (!empty($files)) {
            $multipart = array_merge($multipart, $files);
        }
        
        return $this->multipartPost("/sendMediaGroup", $multipart);
    }

    /**
     * 统一推送方法
     */
    public function push($content) {
        if (is_string($content)) {
            $content = ['text' => $content];
        }
        
        $inlineKeyboard = $content['inline_keyboard'] ?? ($content['reply_markup']['inline_keyboard'] ?? null);
        
        if (isset($content['media']) && is_array($content['media']) && count($content['media']) > 1) {
            return $this->sendMediaGroup($content['media'], $content['text'] ?? '');
        }
        
        if (isset($content['image']) || (isset($content['media']) && is_array($content['media']) && count($content['media']) == 1)) {
            $imageUrl = $content['image'] ?? $content['media'][0]['media'];
            return $this->sendPhoto($imageUrl, $content['text'] ?? '', $inlineKeyboard);
        }
        
        $message = $content['text'] ?? '';
        if (isset($content['links']) && is_array($content['links'])) {
            $message .= "\n\n";
            foreach ($content['links'] as $link) {
                $title = $link['title'] ?? $link['url'];
                $message .= "<a href=\"{$link['url']}\">{$title}</a>\n";
            }
        } else if (isset($content['link'])) {
            $message .= "\n\n";
            $title = $content['link_title'] ?? $content['link'];
            $message .= "<a href=\"{$content['link']}\">{$title}</a>";
        }
        
        return $this->sendText($message, $inlineKeyboard);
    }

    /**
     * 创建内联键盘
     */
    public function createInlineKeyboard(array $buttons, int $columns = 1) {
        $keyboard = [];
        $row = [];
        foreach ($buttons as $index => $button) {
            $buttonData = [];
            if (isset($button['url'])) {
                $buttonData = ['text' => $button['text'], 'url' => $button['url']];
            } elseif (isset($button['callback_data'])) {
                $buttonData = ['text' => $button['text'], 'callback_data' => $button['callback_data']];
            }
            $row[] = $buttonData;
            if (count($row) >= $columns || $index == count($buttons) - 1) {
                $keyboard[] = $row;
                $row = [];
            }
        }
        return $keyboard;
    }

    private function jsonPost(string $endpoint, array $postData) {
        try {
            $response = $this->client->post($this->apiBaseUrl . $endpoint, ['json' => $postData]);
            $responseBody = json_decode($response->getBody(), true);
            return ($responseBody['ok'] ?? false) ? $responseBody : false;
        } catch (GuzzleException $e) {
            return false;
        }
    }

    private function multipartPost(string $endpoint, array $multipart) {
        try {
            $response = $this->client->post($this->apiBaseUrl . $endpoint, ['multipart' => $multipart]);
            $responseBody = json_decode($response->getBody(), true);
            return ($responseBody['ok'] ?? false) ? $responseBody : false;
        } catch (GuzzleException $e) {
            return false;
        }
    }
}