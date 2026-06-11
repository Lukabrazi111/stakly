# FACEIT Developer Support — Questions

Three questions for FACEIT developer support about the Data API + webhooks.

## Context

We're integrating FACEIT for outcome verification on a CS2 staking platform. Two players take a stake, play a 5v5 match on FACEIT, and our backend uses the Data API to confirm the result before settling payouts. We poll on user-driven triggers (page visit, chat send) plus a low-frequency cron, and we'd like to add webhooks for instant confirmation.

## 1. Rate limits on the production Data API key

- What is the request quota (per minute / per hour / per day) for a production server-side API key obtained via App Studio?
- When we receive a `429 Too Many Requests`, which response header should our retry logic honor — `Retry-After` (seconds or HTTP-date), `X-RateLimit-Reset` (epoch), or another field?
- Are quotas global to the API key, or per-endpoint? Specifically interested in `/data/v4/players/{player_id}/history` and `/data/v4/matches/{match_id}`.

## 2. Webhook retry policy

- What is the retry budget for a failed webhook delivery (max attempts, backoff, total window)?
- Which response codes trigger a retry — `5xx` only, or also `4xx` (e.g. `408`, `429`)?
- Is there a way to manually replay a failed delivery from the dashboard, or is the delivery considered terminal after the budget exhausts?

## 3. Webhook egress IPs

- What IP addresses (or CIDR ranges) do FACEIT webhooks originate from?
- Are they stable, or do they rotate? If they rotate, is there an endpoint we can query or a channel where changes are announced?

## Why these matter

- **Rate limits + 429 header**: tunes our circuit breaker and retry backoff so we don't hammer the API during incidents.
- **Webhook retry policy**: shapes our idempotency design (handling at-least-once delivery) and dead-letter behavior.
- **Egress IPs**: lets us add an IP allowlist on top of the shared-secret check on our webhook receiver, for defense in depth.

Thanks!
