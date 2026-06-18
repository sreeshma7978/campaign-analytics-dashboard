import { Head } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

export default function Dashboard() {
    const [campaignId, setCampaignId] = useState('camp-1');
    const [draftCampaignId, setDraftCampaignId] = useState('camp-1');
    const [campaigns, setCampaigns] = useState([]);
    const [stats, setStats] = useState(null);
    const [lastUpdatedAt, setLastUpdatedAt] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [eventTotal, setEventTotal] = useState(1000);
    const [burstConcurrency, setBurstConcurrency] = useState(25);
    const [burstState, setBurstState] = useState({
        running: false,
        sent: 0,
        failed: 0,
    });
    const abortRef = useRef(null);

    const fetchCampaigns = useCallback(async () => {
        try {
            const response = await axios.get('/api/campaigns', { timeout: 8000 });
            const nextCampaigns = response.data.data ?? [];

            setCampaigns(nextCampaigns);

            if (nextCampaigns.length > 0) {
                setCampaignId((currentCampaignId) => {
                    if (currentCampaignId !== 'camp-1') {
                        return currentCampaignId;
                    }

                    setDraftCampaignId(nextCampaigns[0].id);

                    return nextCampaigns[0].id;
                });
            }
        } catch {
            setCampaigns([]);
        }
    }, []);

    const fetchStats = useCallback(async (isInitial = false) => {
        if (!campaignId.trim()) {
            return;
        }

        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;

        setError('');
        setLoading(isInitial);
        setIsRefreshing(!isInitial);

        try {
            const response = await axios.get(
                `/api/campaigns/${encodeURIComponent(campaignId)}/stats`,
                {
                    signal: controller.signal,
                    timeout: 8000,
                },
            );

            setStats(response.data);
            setLastUpdatedAt(new Date());
        } catch (caughtError) {
            if (axios.isCancel(caughtError)) {
                return;
            }

            setError('Stats endpoint is unreachable.');
        } finally {
            setLoading(false);
            setIsRefreshing(false);
        }
    }, [campaignId]);

    useEffect(() => {
        fetchCampaigns();
    }, [fetchCampaigns]);

    useEffect(() => {
        fetchStats(true);
        const intervalId = window.setInterval(() => fetchStats(false), 5000);

        return () => {
            window.clearInterval(intervalId);
            abortRef.current?.abort();
        };
    }, [fetchStats]);

    const counts = stats ?? {
        sent: 0,
        opened: 0,
        clicked: 0,
        bounced: 0,
    };

    const isEmpty = !loading && !error && Object.values(counts).every((value) => value === 0);

    const rates = useMemo(() => {
        const denominator = counts.sent + counts.opened + counts.clicked + counts.bounced;
        const toRate = (value) => (denominator > 0 ? (value / denominator) * 100 : 0);

        return {
            opened: toRate(counts.opened),
            clicked: toRate(counts.clicked),
            bounced: toRate(counts.bounced),
        };
    }, [counts]);

    const metricCards = [
        { label: 'Sent', value: counts.sent, tone: 'border-slate-300' },
        { label: 'Opened', value: counts.opened, tone: 'border-emerald-400' },
        { label: 'Clicked', value: counts.clicked, tone: 'border-sky-400' },
        { label: 'Bounced', value: counts.bounced, tone: 'border-rose-400' },
    ];

    const rateCards = [
        { label: 'Open rate', value: rates.opened },
        { label: 'Click rate', value: rates.clicked },
        { label: 'Bounce rate', value: rates.bounced },
    ];

    const selectedCampaign = campaigns.find((campaign) => campaign.id === campaignId);
    const campaignTitle = selectedCampaign?.name ?? campaignId;

    const submitCampaign = (event) => {
        event.preventDefault();
        const nextCampaignId = draftCampaignId.trim();

        if (nextCampaignId) {
            setCampaignId(nextCampaignId);
        }
    };

    const postEvent = async (sequence) => {
        const eventTypes = ['sent', 'opened', 'clicked', 'bounced'];
        const type = eventTypes[sequence % eventTypes.length];

        await axios.post('/api/events', {
            event_id: `${campaignId}-${Date.now()}-${sequence}-${crypto.randomUUID()}`,
            campaign_id: campaignId,
            type,
            timestamp: new Date().toISOString(),
        });
    };

    const runBurst = async () => {
        const total = Math.max(1, Number(eventTotal) || 1);
        const concurrency = Math.min(Math.max(1, Number(burstConcurrency) || 1), 100);
        let nextIndex = 0;

        setBurstState({ running: true, sent: 0, failed: 0 });

        const worker = async () => {
            while (nextIndex < total) {
                const currentIndex = nextIndex;
                nextIndex += 1;

                try {
                    await postEvent(currentIndex);
                    setBurstState((current) => ({
                        ...current,
                        sent: current.sent + 1,
                    }));
                } catch {
                    setBurstState((current) => ({
                        ...current,
                        failed: current.failed + 1,
                    }));
                }
            }
        };

        await Promise.all(Array.from({ length: concurrency }, worker));
        setBurstState((current) => ({ ...current, running: false }));
        fetchCampaigns();
        fetchStats(false);
    };

    const formattedLastUpdated = lastUpdatedAt
        ? new Intl.DateTimeFormat(undefined, {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        }).format(lastUpdatedAt)
        : 'Never';

    return (
        <div className="min-h-screen bg-zinc-50 text-zinc-950">
            <Head title="Dashboard" />

            <main className="mx-auto flex max-w-7xl flex-col gap-8 px-4 py-6 sm:px-6 lg:px-8">
                <section className="flex flex-col justify-between gap-4 border-b border-zinc-200 pb-6 lg:flex-row lg:items-end">
                    <div>
                        <p className="text-sm font-semibold uppercase tracking-wide text-teal-700">
                            MailerCloud Analytics
                        </p>
                        <h1 className="mt-2 text-3xl font-semibold text-zinc-950 sm:text-4xl">
                            Live campaign dashboard
                        </h1>
                    </div>

                    <form onSubmit={submitCampaign} className="flex w-full flex-col gap-3 sm:max-w-xl sm:flex-row">
                        <label className="flex-1">
                            <span className="mb-1 block text-sm font-medium text-zinc-700">
                                Campaign
                            </span>
                            <input
                                value={draftCampaignId}
                                onChange={(event) => setDraftCampaignId(event.target.value)}
                                className="h-11 w-full rounded-md border-zinc-300 bg-white text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600"
                                placeholder="camp-1"
                            />
                        </label>
                        {campaigns.length > 0 && (
                            <label className="flex-1">
                                <span className="mb-1 block text-sm font-medium text-zinc-700">
                                    Select existing
                                </span>
                                <select
                                    value={campaigns.some((campaign) => campaign.id === campaignId) ? campaignId : campaigns[0].id}
                                    onChange={(event) => {
                                        setCampaignId(event.target.value);
                                        setDraftCampaignId(event.target.value);
                                    }}
                                    className="h-11 w-full rounded-md border-zinc-300 bg-white text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600"
                                >
                                    {campaigns.map((campaign) => (
                                        <option key={campaign.id} value={campaign.id}>
                                            {campaign.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                        <button
                            type="submit"
                            className="mt-auto h-11 rounded-md bg-zinc-950 px-5 text-sm font-semibold text-white transition hover:bg-zinc-800 disabled:cursor-not-allowed disabled:bg-zinc-400"
                            disabled={!draftCampaignId.trim()}
                        >
                            Load
                        </button>
                    </form>
                </section>

                <section className="grid gap-4 lg:grid-cols-[1fr_320px]">
                    <div className="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                        <div className="flex flex-col gap-2 border-b border-zinc-100 pb-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 className="text-lg font-semibold text-zinc-950">
                                    {campaignTitle}
                                </h2>
                                <p className="text-sm text-zinc-500">
                                    {campaignTitle !== campaignId && `${campaignId} · `}
                                    Last updated: {formattedLastUpdated}
                                </p>
                            </div>
                            <div className="flex items-center gap-3 text-sm">
                                {isRefreshing && (
                                    <span className="font-medium text-teal-700">
                                        Refreshing
                                    </span>
                                )}
                                <button
                                    type="button"
                                    onClick={() => fetchStats(false)}
                                    className="rounded-md border border-zinc-300 px-4 py-2 font-semibold text-zinc-800 transition hover:border-zinc-500"
                                >
                                    Refresh
                                </button>
                            </div>
                        </div>

                        {loading && (
                            <div className="grid gap-4 py-6 sm:grid-cols-2 xl:grid-cols-4">
                                {[0, 1, 2, 3].map((item) => (
                                    <div key={item} className="h-32 animate-pulse rounded-lg bg-zinc-100" />
                                ))}
                            </div>
                        )}

                        {error && (
                            <div className="mt-5 rounded-md border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
                                {error}
                            </div>
                        )}

                        {isEmpty && (
                            <div className="mt-5 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm font-medium text-amber-900">
                                This campaign has no engagement events yet.
                            </div>
                        )}

                        {!loading && !error && (
                            <>
                                <div className="grid gap-4 py-6 sm:grid-cols-2 xl:grid-cols-4">
                                    {metricCards.map((metric) => (
                                        <article
                                            key={metric.label}
                                            className={`rounded-lg border-l-4 ${metric.tone} border-y border-r border-zinc-200 bg-white p-4`}
                                        >
                                            <p className="text-sm font-medium text-zinc-500">
                                                {metric.label}
                                            </p>
                                            <p className="mt-3 text-3xl font-semibold tabular-nums text-zinc-950">
                                                {metric.value.toLocaleString()}
                                            </p>
                                        </article>
                                    ))}
                                </div>

                                <div className="grid gap-4 border-t border-zinc-100 pt-5 md:grid-cols-3">
                                    {rateCards.map((rate) => (
                                        <article key={rate.label} className="rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                                            <p className="text-sm font-medium text-zinc-500">
                                                {rate.label}
                                            </p>
                                            <p className="mt-2 text-2xl font-semibold tabular-nums text-zinc-950">
                                                {rate.value.toFixed(2)}%
                                            </p>
                                        </article>
                                    ))}
                                </div>
                            </>
                        )}
                    </div>

                    <aside className="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                        <h2 className="text-lg font-semibold text-zinc-950">
                            Burst generator
                        </h2>
                        <div className="mt-5 space-y-4">
                            <label className="block">
                                <span className="mb-1 block text-sm font-medium text-zinc-700">
                                    Events
                                </span>
                                <input
                                    type="number"
                                    min="1"
                                    max="50000"
                                    value={eventTotal}
                                    onChange={(event) => setEventTotal(event.target.value)}
                                    className="h-11 w-full rounded-md border-zinc-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600"
                                />
                            </label>
                            <label className="block">
                                <span className="mb-1 block text-sm font-medium text-zinc-700">
                                    Concurrency
                                </span>
                                <input
                                    type="number"
                                    min="1"
                                    max="100"
                                    value={burstConcurrency}
                                    onChange={(event) => setBurstConcurrency(event.target.value)}
                                    className="h-11 w-full rounded-md border-zinc-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600"
                                />
                            </label>
                            <button
                                type="button"
                                onClick={runBurst}
                                disabled={burstState.running || !campaignId.trim()}
                                className="h-11 w-full rounded-md bg-teal-700 px-4 text-sm font-semibold text-white transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:bg-zinc-400"
                            >
                                {burstState.running ? 'Running' : 'Send burst'}
                            </button>
                        </div>

                        <div className="mt-5 grid grid-cols-2 gap-3 text-sm">
                            <div className="rounded-md bg-zinc-100 p-3">
                                <p className="font-medium text-zinc-500">Accepted</p>
                                <p className="mt-1 text-xl font-semibold tabular-nums">
                                    {burstState.sent.toLocaleString()}
                                </p>
                            </div>
                            <div className="rounded-md bg-zinc-100 p-3">
                                <p className="font-medium text-zinc-500">Failed</p>
                                <p className="mt-1 text-xl font-semibold tabular-nums">
                                    {burstState.failed.toLocaleString()}
                                </p>
                            </div>
                        </div>
                    </aside>
                </section>
            </main>
        </div>
    );
}
