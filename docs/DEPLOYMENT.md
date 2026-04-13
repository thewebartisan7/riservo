# Deployment Guide

Server requirements and configuration for running riservo.ch in production.

---

## Server Requirements

- PHP 8.3+ with extensions: `mbstring`, `openssl`, `pdo`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `gd` (or `imagick`)
- MariaDB 10.6+ (or MySQL 8.0+)
- Node.js 20+ (for building frontend assets)
- Composer 2.x

---

## Environment Variables

### Required `.env` keys for production

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://riservo.ch

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=riservo
DB_USERNAME=
DB_PASSWORD=

QUEUE_CONNECTION=database

# Hostpoint SMTP
MAIL_MAILER=smtp
MAIL_HOST=asmtp.mail.hostpoint.ch
MAIL_PORT=587
MAIL_USERNAME=noreply@riservo.ch
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@riservo.ch
MAIL_FROM_NAME="riservo"
```

---

## Scheduler (Cron)

The Laravel scheduler must run every minute. Add this cron entry on the server:

```cron
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

### Scheduled commands

| Command | Frequency | Description |
|---------|-----------|-------------|
| `bookings:send-reminders` | Every 5 minutes | Sends reminder emails based on each business's `reminder_hours` config |
| `bookings:auto-complete` | Every 15 minutes | Transitions confirmed bookings past their end time to `completed` status |

---

## Queue Worker

The application uses the `database` queue driver. A queue worker must be running to process queued jobs (email notifications).

### Manual start (development / testing)

```bash
php artisan queue:work --sleep=3 --tries=3 --timeout=90
```

### Supervisor (production)

Use Supervisor to keep the queue worker running and auto-restart on failure.

**Install Supervisor:**
```bash
sudo apt-get install supervisor
```

**Configuration** (`/etc/supervisor/conf.d/riservo-worker.conf`):

```ini
[program:riservo-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path-to-project/artisan queue:work database --sleep=3 --tries=3 --timeout=90 --max-jobs=1000 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path-to-project/storage/logs/worker.log
stopwaitsecs=3600
```

**Start Supervisor:**
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start riservo-worker:*
```

After deploying new code, restart the worker:
```bash
php artisan queue:restart
```

---

## Build Frontend Assets

```bash
npm ci
npm run build
```

---

## Post-Deploy Commands

Run after each deployment:

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```
