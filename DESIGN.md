# Design Notes

## Schema and indexes

`campaigns`

- `id` is the external campaign id used by incoming events, for example `camp-1`.
- `name` is dashboard metadata. When events create a campaign automatically, the id is used as the default name.
- `deleted_at` enables soft deletes for metadata/archive use cases.
- There is no foreign key from `campaign_events` to `campaigns` in this small build, so ingestion does not depend on campaign metadata being present first.

`campaign_events`

- `event_id` is unique and is the idempotency key. Duplicate deliveries are accepted but ignored for counting.
- `campaign_id` is indexed for campaign-level queries and future reconciliation jobs.
- `type` stores `sent`, `opened`, `clicked`, or `bounced`.
- `occurred_at` stores the event timestamp from the producer.
- Composite index `(campaign_id, type, occurred_at)` supports audit/rebuild queries by campaign and type.

`campaign_stats`

- One row per `campaign_id`.
- Counter columns: `sent`, `opened`, `clicked`, `bounced`.
- `campaign_id` is the primary key.
- Stats reads are O(1) and do not aggregate over the raw event table.
- No soft deletes are used here because this table is a derived read model. If a campaign is archived, the metadata row can be soft-deleted while the counters remain available for audit/rebuild.

## Ingestion flow

`POST /api/events` validates with `StoreEngagementEventRequest`, then calls `CampaignAnalyticsService`.

The service creates a campaign metadata row with `insertOrIgnore`, using the campaign id as the default name. It then inserts into `campaign_events` with `insertOrIgnore`. If the event insert succeeds, it dispatches `UpdateCampaignStats` after the transaction commits. If the event insert is ignored, no job is dispatched, so duplicates cannot increment counters.

The stat job uses atomic database upsert/increment SQL:

- First event for a campaign creates the stats row with one counter set to `1`.
- Later events increment only the relevant counter.
- `last_event_at` keeps the latest producer timestamp.

## Handling 20,000 events/sec

This implementation is a correct core, not the final high-scale architecture. To sustain 20k events/sec reliably:

- Put the API behind multiple Laravel workers with PHP-FPM or Octane.
- Keep the synchronous request path small: validate, persist raw event, enqueue aggregation.
- Use Redis, SQS, Kafka, or another durable queue instead of the database queue for burst absorption.
- Batch stat updates in workers by `(campaign_id, type)` every 1-3 seconds to reduce counter write amplification.
- Partition or shard `campaign_events` by time or campaign hash once the table is large.
- Keep `campaign_stats` hot in MySQL or Redis, with periodic reconciliation from raw events.

## No event loss

The raw event insert is the source of truth. The API only returns success after MySQL accepts the event row or confirms it is a duplicate. If aggregation jobs fail, the raw table still has the event, and counters can be rebuilt by replaying `campaign_events`.

For production, the queue should be durable and monitored. Failed jobs should go to `failed_jobs`, alert the team, and be retried or replayed.

## Duplicate handling

The unique index on `campaign_events.event_id` is the hard guarantee. It works even when duplicate requests arrive concurrently on different app servers. Application checks alone would race; the database constraint does not.

## Slow or unavailable database

If MySQL is slow, ingestion latency increases because the raw event must be durably written before success. That is intentional for the "no events lost" requirement.

If MySQL is down, the API returns an error and the client should retry with the same `event_id`. Because event ids are idempotent, retrying is safe.

At higher scale, use a durable front buffer such as Kafka/SQS so the API can acknowledge once the event is durably written to the log, then consumers write to MySQL.

## Dashboard polling behavior

The React dashboard polls every 5 seconds and aborts the previous in-flight request when a new poll starts or the campaign changes. It shows loading, error, empty, refreshing, and last-updated states. If the stats endpoint is slow or fails, the stale last-updated time remains visible.

## Hundreds of dashboard users

Dashboard reads hit `campaign_stats`, so each request is a primary-key lookup. Hundreds of users polling every 5 seconds should be manageable. For larger fan-out:

- Add HTTP caching with short TTLs.
- Cache campaign stats in Redis for 1-5 seconds.
- Use Server-Sent Events or WebSockets to fan out updates from one backend read per campaign.
- Rate limit and protect high-cardinality polling patterns.
