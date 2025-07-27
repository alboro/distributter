# Distributter - Social Networks Synchronization Tool

A PHP-based tool for synchronizing content between different social networks, primarily VK and Telegram. The application automatically retrieves posts from one platform and publishes them to another, maintaining content consistency across social media channels.

## Features

- **Bidirectional Sync**: VK ↔ Telegram synchronization
- **Rich Content Support**: Text, photos, videos, links, and polls
- **Smart Filtering**: Skip ads, reposts, and tagged content
- **Rate Limiting**: Built-in retry mechanisms for API rate limits
- **Deployment Ready**: Includes deployment scripts and cron job support
- **Robust Error Handling**: Comprehensive logging and error recovery
- **Storage**: JSON-based relationship tracking between posts

## Architecture

The project follows a modular architecture with clear separation of concerns:

```
src/Sc/
├── Channels/           # Platform-specific implementations
│   ├── Vk/            # VK.com integration
│   ├── Tg/            # Telegram integration
│   ├── Instagram/     # Todo..
│   └── Fb/            # Todo..
├── Config/            # Configuration classes
├── Dto/               # Data Transfer Objects
├── Filter/            # Content filtering logic
├── Model/             # Core domain models (Post, Poll, etc.)
└── Service/           # Business logic services
```

## Requirements

- PHP 8.1+
- Composer
- VK API access token
- Telegram Bot API token
- SSH access to deployment server (for production)

## VK Installation
* Create an app on vk.com and get app token: https://vk.com/apps?act=manage
* Get authorization token: https://oauth.vk.com/authorize?client_id=YOUR_APP_ID&display=page&redirect_uri=https://oauth.vk.com/blank.html&scope=groups,wall,manage,photos,docs,offline,video,notes&response_type=token&v=5.131

## TG Installation
* Create a Telegram bot via @BotFather
* Create a Telegram channel
* Add the bot to the channel as an admin with posting permissions

## Configuration

### Environment Setup
```sh
cp .env.example .env
```

Edit `.env` with your credentials:
```env
# VK Configuration
VK_TOKEN=your_vk_access_token
VK_GROUP_ID=-123456789
VK_EXCLUDE_POST_IDS=1,2,3
VK_IGNORE_TAG=#ignore

# Telegram Configuration
TG_BOT_TOKEN=123456789:ABCdefGHIjklMNOpqrsTUVwxyz
TG_CHANNEL_ID=-1001234567890
TG_ENABLE_NOTIFICATIONS=false

# MadelineProto Settings
MADELINE_SESSION_PATH=./session.madeline
MADELINE_API_ID=12345
MADELINE_API_HASH=your_api_hash

# Application Settings
STORAGE_FILE_PATH=./storage.v3.json
LOG_FILE_PATH=./log.log
REQUEST_TIMEOUT_SEC=30
ITEM_COUNT=5

# Facebook setttings
# FB_PAGE_ACCESS_TOKEN=your_facebook_page_token
# FB_PAGE_ID=your_facebook_page_id
```

### Deployment Configuration
```sh
cp deploy.conf.dist deploy.conf
```

Edit `deploy.conf`:
```bash
export DEPLOY_HOST=your-server.com
export DEPLOY_USER=deploy
export DEPLOY_PATH=/var/www/distributter
export DEPLOY_BRANCH=main
export SSH_KEY=$HOME/.ssh/id_rsa
```

## Installation

### Local Development
```sh
git clone <https://github.com/alboro/distributter>
cd distributter
composer install
cp .env.example .env
# Edit .env with your credentials
```

### Production Deployment
```sh
# Initial setup
source deploy.conf && ./bin/deploy.sh force

# Set up cron job for automatic synchronization
# Add to crontab: */5 * * * * cd /var/www/distributter && php bin/distributter.php
```
Get in Facebook Developers Console:
Permissions needed: pages_read_engagement, pages_manage_posts

### Docker
```sh
# Rebuild the image
docker build -t distributter .

# Run with a permanent job
docker run -d --name distributter distributter

# show logs
docker logs -f distributter
```
## Usage

### Manual Synchronization
```sh
php bin/distributter.php
```

### Telegram Authorization (First Time)
```sh
php bin/auth-telegram.php
```

## Deploy
```sh
# Force deployment without confirmation  
source deploy.conf && ./bin/deploy.sh force

# Rollback to previous version
source deploy.conf && ./bin/deploy.sh rollback

# Check status (now shows cron jobs)
source deploy.conf && ./bin/deploy.sh status

# View logs in real time
source deploy.conf && ./bin/deploy.sh logs

# Connect to server via SSH
source deploy.conf && ./bin/deploy.sh ssh
```

## Monitoring & Logs

### Application Logs
```sh
tail -f log.log
```

### MadelineProto Logs
```sh
tail -f MadelineProto.log
```

## Content Processing

### Supported Content Types
- Single photo with text
- Just texts
- Polls

### Filtering Rules
- Posts marked as ads are automatically skipped
- Posts with specified ignore tags are filtered out
- **VK**:
- - reposts are filtered out
- - Posts from unauthorized users are blocked
- - Post 4096 characters max  
- **Telegram**:
- - Automatic message splitting for long content
- - Polls: max 10 options, 300 character question limit

## Troubleshooting

### Common Issues

#### Sync Process Stuck
```sh
sudo pkill -f "distributter.php"
```

#### MadelineProto Authorization Issues
```sh
rm -rf session.madeline/
php bin/auth-telegram.php
```

## Development

### Adding New Platforms
1. Create new retriever class implementing `RetrieverInterface`
2. Create new sender class implementing `SenderInterface`
3. Add configuration classes in `Config/` directory
4. Register new services in `Synchronizer`

## TODO
* vk is text (not quite, some kind of markdown), tg is html, reflect it in Post model
* think about internal sender/retriever,
* not all links are parsed from tg
* now the new one is the one we don't know about. but when the third integration appears, refactoring will be necessary. the new one can only be defined relative to the specific system where we send it.
* retrieve only new posts (check for existence in local storage, calc individual item count for each retriever)
* Group retrieved posts from Telegram (see `src/Sc/Channels/Tg/TelegramRetriever.php:16`), use also tags or time as group criteria
* add special tags which define if to remove links or transform them into just text (tg supports html, vk - no)
* Add auto-tests and CI/CD pipeline
* VK: To parse `[id2911722|Alex Ivanov]` in a more correct way, transform internal feed links into links of appropriate channel
* Add system abstraction (e.g., tg2tg sync) and more flexibility via configs
* Support for gradual synchronization of the old channel with the newer one. Individual limits for retrievers coordinated with this.
* Add RSS support
* Add Instagram support
* Add Facebook support  
* Add polls support for VK sender (see `\Sc\Channels\Vk\VkSender::supportsPolls`)
* Move post list synchronization to separate processes for better stability

## License

This project is licensed under the MIT License - see the LICENSE file for details.
