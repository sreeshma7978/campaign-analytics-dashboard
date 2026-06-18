#!/usr/bin/env node

import crypto from 'node:crypto';
import { setTimeout as sleep } from 'node:timers/promises';

const args = Object.fromEntries(
    process.argv.slice(2).map((arg) => {
        const [key, value = true] = arg.replace(/^--/, '').split('=');

        return [key, value];
    }),
);

const baseUrl = String(args.url ?? 'http://127.0.0.1:8000').replace(/\/$/, '');
const campaignId = String(args.campaign ?? 'camp-1');
const total = Math.max(1, Number(args.events ?? 1000));
const concurrency = Math.min(Math.max(1, Number(args.concurrency ?? 50)), 500);
const typeWeights = ['sent', 'sent', 'sent', 'opened', 'opened', 'clicked', 'bounced'];

let nextIndex = 0;
let accepted = 0;
let failed = 0;
const startedAt = Date.now();

async function postEvent(sequence) {
    const type = typeWeights[sequence % typeWeights.length];
    const response = await fetch(`${baseUrl}/api/events`, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            event_id: `${campaignId}-${startedAt}-${sequence}-${crypto.randomUUID()}`,
            campaign_id: campaignId,
            type,
            timestamp: new Date().toISOString(),
        }),
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }
}

async function worker() {
    while (nextIndex < total) {
        const sequence = nextIndex;
        nextIndex += 1;

        try {
            await postEvent(sequence);
            accepted += 1;
        } catch {
            failed += 1;
            await sleep(100);
        }
    }
}

await Promise.all(Array.from({ length: concurrency }, worker));

const seconds = Math.max((Date.now() - startedAt) / 1000, 0.001);

console.table({
    campaign: campaignId,
    requested: total,
    accepted,
    failed,
    seconds: seconds.toFixed(2),
    events_per_second: Math.round(accepted / seconds),
});
