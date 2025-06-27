<?php

namespace Sc;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use TelegramBot\Api\BotApi;
use VK\Client\VKApiClient;
class Tg2Vk
{
    private bool $useVkApi = false;
    private bool $enableNotification = false;
    /** @var BotApi */
    private $tgBot;
    private array $excludePostIds = [];

    /** @var string */
    private $tgChannelId;

    /** @var string */
    private $vkGroupId;

    /** @var string */
    private $vkToken;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    private VKApiClient $vk;

    /** @var int */
    private $tgLastPostDateTmp;

    /** @var Storage */
    private $storage;

    public function __construct()
    {
        $this->vkToken     = getenv('VK_TOKEN');
        $this->vkGroupId   = getenv('VK_GROUP_ID');
        $this->storage     = Storage::load();
        $this->tgBot       = new BotApi(getenv('TG_BOT_TOKEN'));
        $this->tgChannelId = getenv('TG_CHANNEL_ID');
        $this->vk          = new VKApiClient();
        $tgProxyDSN        = getenv('TG_PROXY_DSN');
        if (!empty($tgProxyDSN)) {
            $this->tgBot->setProxy($tgProxyDSN);
        }

        $this->logger = new Logger('vk2tg');
        $this->logger->pushHandler(new StreamHandler(STDOUT, Logger::DEBUG));
    }

    public function send(): void
    {
        $this->logger->debug('Request tg posts');

        $syncData = $this->tgBot->getUpdates(-1);
        var_export($syncData); die;

        $this->logger->debug('Done.');
    }

    private function processItem(array $vkItem): void
    {
        $this->validate($vkItem);

        $messageId = $author = null;
        $text = $vkItem['text'];
        $vkItemId = (int)$vkItem['id'];

        if (isset($vkItem['signer_id'])) {
            $response = $this->vk->users()->get(getenv('VK_TOKEN'), [
                'user_ids'  => [$vkItem['signer_id']],
                'fields'    => [],
            ]);
            $author = $response[0]['first_name'] . ' ' . $response[0]['last_name'];
            if ($author === 'DELETED') {
                unset($author);
            }
        }

        $this->parsePoll($vkItem);

        if (isset($vkItem['copy_history'])) {
            throw new \RuntimeException('reposts are not supported yet');
        }

        foreach ($this->parseVideos($vkItem) as $url) {
            $text = sprintf("\n<a href='%s'>%s</a>\n", $url, $url) . $text;
        }
        foreach ($this->parseLinks($vkItem) as $title => $url) {
            $text .= sprintf("\n<a href='%s'>%s</a>\n", $url, $title);
        }
        $photos = $this->parsePhotos($vkItem);

        if (empty($text) && !empty($photos)) {
            $this->logger->info($msg = 'Prevent sending photo with no text', ['photos' => array_values($photos), 'id' => $vkItemId, 'tgId' => $messageId]);
            throw new \RuntimeException($msg);
        }

        if (count($photos) === 1) {
            if ($author !== 'Александр Демченко' && strlen($text) > 500) {
                $text .= "\n" . '© ' . $author;
            }
            try {
                $messageId = !$this->useVkApi ? 123 : $this->tgBot->sendPhoto($this->tgChannelId, $photos[0], $text, null, null, !$this->enableNotification, 'html')->getMessageId();
                $this->useVkApi && $this->storage->addId($vkItemId, $messageId);
                $this->logger->info('Send new photo', ['id' => $vkItemId, 'text' => $text, 'photo' => $photos[0], 'tgId' => $messageId]);
                return;
            } catch (\Exception $e) {
                $this->logger->error('send Photo', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString(), 'id' => $vkItemId]);
                if ($e->getMessage() !== 'Bad Request: MEDIA_CAPTION_TOO_LONG') {
                    return;
                }
            }

        }

        foreach ($photos as $index => $url) {
            $pictureText = sprintf('Изображение %d', $index + 1);
            if (isset($author) && $index === 0) {
                $pictureText = $author;
            }

            $text .= sprintf("\n<a href='%s'>%s</a>\n", $url, $pictureText);
        }
        try {
            if (!empty($text)) {
                $messageId = !$this->useVkApi ? 123 : $this->tgBot->sendMessage($this->tgChannelId, $text, 'html', false, null, null, !$this->enableNotification)->getMessageId();
                $this->useVkApi && $this->storage->addId($vkItemId, $messageId);
                $this->logger->info('Send new post', ['id' => $vkItemId, 'text' => $text, 'tgId' => $messageId]);
            }

        } catch (\Throwable $e) {
            $this->logger->error('send Message', ['text' => $text, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }

    private function parseLinks(array $vkItem): array
    {
        $links = [];
        foreach ($vkItem['attachments'] ?? [] as $vkAttachment) {
            if ('link' === $vkAttachment['type']) {
                $links[$vkAttachment['link']['title']] = $vkAttachment['link']['url'];
            }
        }
        return $links;
    }

    private function parsePhotos(array $vkItem): array
    {
        $photos = [];
        foreach ($vkItem['attachments'] ?? [] as $vkAttachment) {
            if ('photo' === $vkAttachment['type']) {
                $photoSizeCount = count($vkAttachment['photo']['sizes']);
                $photos[] = $vkAttachment['photo']['sizes'][$photoSizeCount - 1]['url'];
            }
        }
        return $photos;
    }

    private function parsePoll(array $vkItem)
    {
        $photos = [];
        foreach ($vkItem['attachments'] ?? [] as $vkAttachment) {
            if ('poll' === $vkAttachment['type']) {
                throw new \RuntimeException('poll is not supported yet');
            }
        }
        return $photos;
    }

    private function parseVideos(array $vkItem): array
    {
        $videos = [];
        foreach ($vkItem['attachments'] ?? [] as $vkAttachment) {
            if ('video' === $vkAttachment['type']) {
                $link = sprintf('https://vk.com/video%s_%s', $vkAttachment['video']['owner_id'], $vkAttachment['video']['id']);
                if (isset($vkAttachment['video']['platform'])) {
                    $requestTimeout = stream_context_create(['http' => ['timeout' => getenv('REQUEST_TIMEOUT_SEC')]]);
                    preg_match('/<iframe [^>]+src=\\\"([^\"]+)\?/', file_get_contents($link, false, $requestTimeout), $match);
                }
                if (isset($match[1])) {
                    $videos[] = str_replace('\\', '', $match[1]);
                    break;
                }

                $videos[] = $link;
            }
        }
        return $videos;
    }

    private function validate(array $vkItem)
    {
        if ((int)$vkItem['date'] <= $this->storage->getLastDate()) {
            // $this->logger->debug('Skip post', ['reason' => 'already posted by date', 'id' => $vkItem['id'], 'date' => $vkItem['date']]);
            // throw new \RuntimeException();;
        }
        if (0 === $this->vkLastPostDateTmp && $this->storage->getLastDate() < (int)$vkItem['date']) {
            $this->logger->debug('Set new last post date', ['date' => $vkItem['date']]);
            $this->vkLastPostDateTmp = (int)$vkItem['date'];
        }
        if ((int)$vkItem['from_id'] !== (int)$this->vkGroupId) {
            $this->logger->debug('Skip post', ['reason' => 'post by alien', 'id' => $vkItem['id']]);
            throw new \RuntimeException();
        }
        if (in_array((int) $vkItem['id'], $this->excludeVkPostIds, true)) {
            $this->logger->debug('Skip post', ['reason' => 'exclude Vk Post Ids', 'id' => $vkItem['id']]);
            throw new \RuntimeException();
        }
        if ($vkItem['marked_as_ads']) {
            $this->logger->debug('Skip post', ['reason' => 'marked as ads', 'id' => $vkItem['id']]);
            throw new \RuntimeException();
        }
        if ($this->storage->hasId($vkItem['id'])) {
            $this->logger->debug('Skip post', ['reason' => 'already posted by id', 'id' => $vkItem['id']]);
            throw new \RuntimeException();
        }
    }
}