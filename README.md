# Campaign Analytics Dashboard

GitHub: [sreeshma7978/campaign-analytics-dashboard](https://github.com/sreeshma7978/campaign-analytics-dashboard)

Laravel 13 + React/Inertia implementation of a high-throughput email engagement analytics slice. The app ingests campaign events, deduplicates repeated deliveries, updates campaign counters through a queue, and shows a live polling dashboard.

## Stack

- Laravel 13
- PHP 8.3+
- React 18 with Inertia
- MySQL 8
- Laravel database queues
- Vite

## Features

- `POST /api/events` ingests one engagement event.
- `GET /api/campaigns/{campaignId}/stats` returns campaign counters.
- `GET /api/campaigns` returns existing campaigns for dashboard selection.
- Duplicate `event_id` values are ignored and never counted twice.
- Raw events are stored in `campaign_events`.
- Aggregated counters are stored in `campaign_stats` for fast dashboard reads.
- Campaign metadata is stored in `campaigns` with soft deletes.
- Dashboard auto-refreshes every 5 seconds.
- Dashboard handles loading, error, empty, and last-updated states.
- Dashboard includes a burst generator for quick manual load testing.
- CLI load generator is available for higher-volume testing.

## Setup

Clone the project:

```bash
git clone https://github.com/sreeshma7978/campaign-analytics-dashboard.git
cd campaign-analytics-dashboard
```

Install dependencies:

```bash
composer install
npm install
```

Create the environment file:

```bash
cp .env.example .env
php artisan key:generate
```

Configure MySQL in `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=campaign_analytics
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=database
```

Run migrations:

```bash
php artisan migrate
```

## Run Locally

Recommended:

```bash
composer run dev
```

This starts:

- Laravel server
- Vite dev server
- Laravel logs
- Queue listener for `analytics,default`

Open:

```text
http://127.0.0.1:8000
```

If running services manually, start the queue worker too:

```bash
php artisan serve
npm run dev
php artisan queue:listen --queue=analytics,default --tries=3 --timeout=0
```

## API Examples

Ingest an event:

```bash
curl -X POST http://127.0.0.1:8000/api/events \
  -H "Content-Type: application/json" \
  -d '{"event_id":"abc-123","campaign_id":"camp-1","type":"opened","timestamp":"2026-06-17T10:00:00Z"}'
```

Get campaign stats:

```bash
curl http://127.0.0.1:8000/api/campaigns/camp-1/stats
```

Expected response shape:

```json
{
  "sent": 0,
  "opened": 1,
  "clicked": 0,
  "bounced": 0
}
```

List campaigns:

```bash
curl http://127.0.0.1:8000/api/campaigns
```

## Dashboard Behavior

- If campaigns exist in the database, the first-created campaign is selected by default.
- If no campaigns exist, the dashboard defaults to the manual input value `camp-1`.
- The select box is shown only when campaigns exist.
- Rates are calculated against total campaign events:

```text
total = sent + opened + clicked + bounced
open rate = opened / total * 100
click rate = clicked / total * 100
bounce rate = bounced / total * 100
```

## Load Generator

Dashboard option:

- Set `Events`.
- Set `Concurrency`.
- Click `Send burst`.

CLI option:

```bash
npm run load -- --url=http://127.0.0.1:8000 --campaign=camp-1 --events=10000 --concurrency=100
```

Meaning:

- `events`: total fake events to send.
- `concurrency`: number of parallel requests.
- `campaign`: campaign id used for generated events.

## Testing

Run focused analytics tests:

```bash
DB_CONNECTION=mysql DB_DATABASE=campaign_analytics php artisan test --filter=AnalyticsApiTest
```

Run the full Laravel test suite:

```bash
DB_CONNECTION=mysql DB_DATABASE=campaign_analytics php artisan test
```

Run frontend production build:

```bash
npm run build
```

Format PHP code:

```bash
vendor/bin/pint
```

## Important Design Choices

- `event_id` has a unique index, so duplicate event deliveries cannot be counted twice.
- Event ingestion uses `insertOrIgnore()` to avoid an extra lookup before insert.
- Stats reads use `campaign_stats`, avoiding expensive `COUNT(*)` queries over raw events.
- Queue jobs update stats after raw events are committed.
- `campaigns` uses soft deletes because it is metadata.
- `campaign_events` does not use soft deletes because raw events are the source of truth.
- `campaign_stats` does not use soft deletes because it is a derived read model.
- Rate limiting protects API endpoints while allowing high ingest bursts.

More scale and failure reasoning is documented in [DESIGN.md](DESIGN.md).
