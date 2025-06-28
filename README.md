https://oauth.vk.com/authorize?client_id=8052550&group_ids=3529945&display=page&scope=groups,wall&response_type=token&v=5.131&state=klop
https://oauth.vk.com/authorize?client_id=8052550&display=page&scope=groups,wall,manage,photos,docs,offline&response_type=code&redirect_uri=http://deism.aldem.ru/&v=5.131&state=klop

https://dvmn.org/encyclopedia/qna/63/kak-poluchit-token-polzovatelja-dlja-vkontakte/


# vk2tg

#### Resend wall posts from vk group to tg channel 

## Installation
* create an app on vk.com and get app token https://vk.com/apps?act=manage
* create a tg bot
* create a tg channel
* add a bot to a channel as admin

```sh
cp .env.example .env
```

.env
```dotenv
TG_BOT_TOKEN=tg-bot-token
TG_PROXY_DSN=socks5://username:pass@host:port //optional
TG_CHANNEL_ID=-tg-channel-id
VK_TOKEN=vk-token
VK_GROUP_ID=-vk-group-id
CHECK_TIMEOUT_SEC=10
REQUEST_TIMEOUT_SEC=5
```

## Usage
```sh
docker pull mrsuh/vk2tg
docker run --env-file .env -v $(pwd)/vk_last_post_date.txt:/app/vk_last_post_date.txt -d mrsuh/vk2tg
docker logs -f <container_id>
```

## Deploy
```sh
# Настройка переменных окружения (один раз)
export DEPLOY_HOST="your-server.com"
export DEPLOY_USER="deploy" 
export DEPLOY_PATH="/var/www/vk2tg"
export SERVICE_NAME="vk2tg"
```

```sh
# Загрузить конфигурацию и выполнить деплой
source deploy.conf && ./bin/deploy.sh

# Деплой с подтверждением
source deploy.conf && ./bin/deploy.sh deploy

# Принудительный деплой без подтверждения  
source deploy.conf && ./bin/deploy.sh force

# Откат к предыдущей версии
source deploy.conf && ./bin/deploy.sh rollback

# Проверить статус (теперь показывает cron задания)
source deploy.conf && ./bin/deploy.sh status

# Просмотр логов в реальном времени
source deploy.conf && ./bin/deploy.sh logs

```

```txt
Vk2Tg (координатор)
├── AppConfig (конфигурация)
├── Storage (хранилище)
├── PostFilter (фильтрация)
├── VkAttachmentParser (парсинг)
├── AuthorService (авторы)
├── MessageFormatter (форматирование)
└── TelegramSender (отправка)
```
