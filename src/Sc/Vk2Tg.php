<?php

namespace Sc;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use TelegramBot\Api\BotApi;
use VK\Client\VKApiClient;
class Vk2Tg
{
    private bool $useTgApi = true;
    private bool $enableNotification = false;
    /** @var BotApi */
    private $tgBot;
    private array $excludeVkPostIds = [
        5200, // кнопка \"пожертвовать\"
        5279, // "text":"Здравствуй, Всем��рный день философии!\n#развлекаемся@deism"
        5288, // "text":"Пришла в голову идея создать темы
        5356, // "text":"В пятницу 25-ого
        5397, // "text":"Деистический VS деистский. Как вообще правильно?
        5483, // "text":"Привет, Новый Год.
        5528, // "text":"Как сообществу использовать функционал \"историй
        6302, // "text":"\"Есть ли Бог? Точный ответ\"
        6617, // "text":"\n<a href='https://alexlotov
        6651, // "text":"Уважая время дорогих участников сообщества, предупреждаю, что набор на клавиатуре нецензурных выражений
        6878, // "text":"Следующая деистическая встреча
        7015, // "text":"Какие вопросы вы бы хотели обсудить, рассмотреть, разобрать, попытаться раскрыть? (на следующих деистических встречах)
        880, // "text":"Мы обзавелись адресом http://vkontakte.ru/deism
        885, // "text":"За последние пару дней обновились наши правила
        930, // "text":"У нас один из самых коротких \"измов\".
        946, // "text":"Сделано.
        958, // "text":"Наше деистическое фи пропавшей нумерации сообщений в обсуждениях. Хнык.
        966, // "text":"Рекомендуем группу, посвящённую Панентеизму (см. наши ссылки).
        1010, // "text":"Деисты, деистки и сочувствующие!\n\nДавайте пригласим в нашу группу интересных людей!
        1011, // "text":"\n<a href='https://vk.com/escience'>Естественные науки [natural science
        1025, // "text":"Привкус чего-то невкусного (носков?)
        1078, // "text":"Всем добрый 2013-ый год! А у нас новый человек в руководстве группы, [id82515593|Михаил Ночевной]
        1089, // "text":"Знаете ли вы, что из шести отцов основателей
        1113, // "text":"Belief in God + God given reason!
        1138, // "text":"Единомышленники прошу вас помочь группе!\nНужно найти в электронном виде труды великих деистов
        1139, // "text":"Великий русский деист!\nhttp://www.lomonosov300.ru/
        1141, // "text":"Кто читал? Поделитесь впечатлениями...\nЯ сейчас читаю...
        1330, // "text":"рекомендую"
        1345, // "text":"Вниманию деистов, деисток и деистят! У нас в сообществе  новый администратор [id151646553|Рамоныч Усов].
        1498, // "text":"Если хотите звоните мне в скайп, могу вечерком порассуждать о деизме и мироустройс��ве ;-)\nп.с. скайп в личку
        1579, //n<a href='https://vk.com/video82515593_165679890'>https://vk.com/video82515593_165679890</a>\nсовременные деисты
        1664, // "text":"советую почитать:\nhttp://ru-deism.livejournal.com
        1720, // "text":"интересная статистика группы
        1780, // "text":"\n<a href='https://vk.com/video82515593_167877846
        1781, // "text":"Братья, давайте порассуждаем на тему что такое деньги и зачем они нам нужны
        1793, // "text":"Как должен деист реагировать на поздравления с Пасхой и иных теистическиз праздников?!
        1817, // "text":"С международным днем деизма, братья!
        1837, // "text":"все кто помнит эту песню - ностальгируйте, кто не слышал ее - послушайте эту замечательную смыслом песню!"
        1852, // "text":"Достижимо ли бессмертие?!\nНужно ли оно нам?!\nПрошу всех к беседе..."
        2949, // "text":"Статья англоязычного единомышленника о проблеме абортов
        3414, // "text":"\n<a href='https://vk.com/video76110813_163405975'>
        3415, // "text":"\n<a href='https://vk.com/video135550_164058059'>ht
        3425, // "text":"братьям
        3443, // "text":"\n<a href='https://vk.com/video-28491742_163670557
        3452, // "text":"в Калифорнии разрешили эвтаназию.
        3465, // "text":"предлагаю к открытому обсуждению темы человеческого ЭГО.
        3472,
        3479, // "text":"наука - современный храм Бога
        3584, // "text":"с очередным оборотом Земли вокруг Солнца!"
        3591, 3629, 3760, 3778, 3869, 3878, 3906, 3943, 3960, 3963, 3979, 3983, 3996, 4003, 4005, 4021, 4042, 4078, 4133, 4200, 4209, 4213, 4215, 4219, 4238, 4247,
        4272, 4284, 4294, 4310, 4313, 4318, 4320, 4368, 4391, 4412, 4430, 4433, 4436, 4438, 4449, 4450, 4465, 4468, 4474, 4488, 4495, 4502, 4520, 4542, 4545, 4550, 4552, 4567, 4580, 4590, 4593, 4596, 4599,4602, 4606, 4622,
        4735, 4744, 5140, 5117, 5114, 5069, 4981, 4972, 4956, 4940, 4938, 4909, 4889, 4863, 4858, 4857, 4854, 4853, 4852, 4851, 4844, 4818, 4808, 4795, 4794, 4773, 4772, 4772, 4771, 4750,
        5165, 5182, 5209, 6823, // Молодой человек пытается донести до людей, почему у нас есть свобода воли
        6908, 4818, 1502
    ];

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
    private $vkLastPostDateTmp;

    /** @var Storage */
    private $storage;

    public function __construct()
    {
        $this->useTgApi    = !getenv('DRY_RUN');
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
        $this->logger->pushHandler(new StreamHandler(__DIR__ . '/../../log.log', Logger::ERROR));
    }

    /**
     * @see https://dev.vk.com/method/wall.pin
     */
    public function pin(): void
    {
        $vkData = $this->vk->wall()->get($this->vkToken, [
            'owner_id' => $this->vkGroupId,
            'count' => 1,
        ]);
        $i = 0;
        do {
            sleep(4);
            $randomPost = $this->vk->wall()->get($this->vkToken, [
                'owner_id' => $this->vkGroupId,
                'offset' => random_int(0, $vkData['count'] - 1),
                'count' => 1,
            ]);
            $randomPostId = (int) $randomPost['items'][0]['id'];
            echo sprintf('[%s]', $randomPostId);

            if (
                $this->storage->hasId($randomPostId)
                && !in_array($randomPostId, $this->excludeVkPostIds, true)
                && empty($randomPost['items'][0]['copy_history'])
            ) {
                var_export($randomPost);
                $this->vk->wall()->pin($this->vkToken, [
                    'owner_id' => $this->vkGroupId,
                    'post_id' => $randomPostId,
                ]);
                break;
            }
        } while (100 === ++$i);
        sleep(4);
    }

    public function send(): void
    {
        $this->logger->debug('Request posts');

        $vkData = $this->vk->wall()->get($this->vkToken, [
            'owner_id' => $this->vkGroupId,
            'offset' => 0,
            'count' => (int) (getenv('ITEM_COUNT') ?: 5),
        ]);
        // $this->logger->debug('got data', ['count' => $vkData['count'], 'items' => $vkData['items']]);

        if (!isset($vkData['items'])) {
            $fileName = sprintf('empty_response_%d.txt', time());
            $this->logger->error('empty response', ['file' => $fileName]);
            file_put_contents($fileName, json_encode($vkData));

            return;
        }

        $this->vkLastPostDateTmp = 0;
        foreach (array_reverse($vkData['items']) as $vkItem) {
            try {
                sleep(1);
                $this->processItem($vkItem);
            } catch (\Throwable $e) {
                $this->logger->error(get_class($e) . ': ' . $e->getMessage());
            }
        }

        if (0 !== $this->vkLastPostDateTmp) {
            $this->storage->setLastDate($this->vkLastPostDateTmp);
        }

        $this->useTgApi && $this->storage->save();
        $this->logger->debug('Done.');
    }

    private function processItem(array $vkItem): void
    {
        $this->validate($vkItem);

        $messageId = $author = null;
        $text = $vkItem['text'];
        $vkItemId = (int)$vkItem['id'];

        // Определяем автора на основе явного указания в тексте поста
        if (isset($vkItem['signer_id']) /*&& $this->shouldShowAuthor($text)*/) {
            $response = $this->vk->users()->get(getenv('VK_TOKEN'), [
                'user_ids'  => [$vkItem['signer_id']],
                'fields'    => [],
            ]);
            $author = $response[0]['first_name'] . ' ' . $response[0]['last_name'];
            if ($author === 'DELETED') {
                unset($author);
            }
        } elseif (isset($vkItem['check_sign']) && $vkItem['check_sign'] === true && isset($vkItem['post_author_data']['author'])/* && $this->shouldShowAuthor($text)*/) {
            // Если включена подпись и есть данные автора
            $response = $this->vk->users()->get(getenv('VK_TOKEN'), [
                'user_ids'  => [$vkItem['post_author_data']['author']],
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
            $this->logger->info($msg = 'Prevent sending photo with no text', ['id' => $vkItemId, 'tgId' => $messageId, 'photos' => array_values($photos)]);
            throw new \RuntimeException($msg);
        }

        if (count($photos) === 1 && strlen($text) < 1000) {
            if ($author !== 'Александр Демченко' && strlen($text) > 500) {
                $text .= "\n" . '© ' . $author;
                unset($author);
            }
            try {
                $messageId = !$this->useTgApi ? 123 : $this->tgBot->sendPhoto($this->tgChannelId, $photos[0], $text, null, null, !$this->enableNotification, 'html')->getMessageId();
                $this->useTgApi && $this->storage->addId($vkItemId, $messageId);
                $this->logger->info('Send new photo', ['id' => $vkItemId, 'tgId' => $messageId, 'text' => $text, 'photo' => $photos[0]]);
                return;
            } catch (\Exception $e) {
                $this->logger->error('send Photo', ['id' => $vkItemId, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
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
                $messageId = !$this->useTgApi ? 123 : $this->tgBot->sendMessage($this->tgChannelId, $text, 'html', false, null, null, !$this->enableNotification)->getMessageId();
                $this->useTgApi && $this->storage->addId($vkItemId, $messageId);
                $this->logger->info('Send new post', ['id' => $vkItemId, 'tgId' => $messageId, 'text' => $text]);
            }

        } catch (\Throwable $e) {
            $this->logger->error('send Message', ['id' => $vkItemId, 'text' => $text, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
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
                    preg_match('/<iframe [^>]+src="([^"]+)\?/', file_get_contents($link, false, $requestTimeout), $match);
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
        $ignoreTag = getenv('TAG_OF_IGNORE') ?: '#vk';
        if (preg_match('/(?:^|\s)' . preg_quote($ignoreTag, '/') . '(?:\s|$)/i', $vkItem['text'])) {
            $this->logger->debug('Skip post', ['reason' => 'tagged with ignore tag', 'id' => $vkItem['id']]);
            throw new \RuntimeException();
        }
        if ($this->storage->hasId((int) $vkItem['id'])) {
            $this->logger->debug('Skip post', ['reason' => 'already posted by id', 'id' => $vkItem['id']]);
            throw new \RuntimeException();
        }
    }
}