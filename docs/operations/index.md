# Operations Guide

Operational documentation is grouped here because the Cyberia stack is more than an HTTP application. It includes relayers, queues, scheduled resolvers, monitoring probes, host heartbeats, bots, AI providers, nodes, and explorer services.

## Core manuals

| Area | Manual | Purpose |
| --- | --- | --- |
| Operator workspace | [Operator console](../console.md) | Attention queue, people, tasks, numbers, machines, chat, and API grants |
| Runtime health | [Service monitoring](../monitoring.md) | Read-only probes, incidents, host heartbeats, and usage |
| Wallet funnel | [Product analytics](../product-analytics.md) | Acquisition, onboarding, funding, activation, and retention |
| Release process | [Releases](../RELEASES.md) | Versioning, changelog, checks, and artifacts |
| Hosted inference | [Inference API](../ai-api.md) | Models, authentication, quotas, streaming, and x402 access |

## Operating principles

- Unknown is not healthy and not down. Preserve `unknown` or `unmeasured` when the system could not find out.
- Probes are read-only. Restarts, funding, retries, and repairs are explicit operator actions or separately controlled jobs.
- Alerts fire on transitions and are marked delivered only after the notification succeeds.
- A durable transaction hash is part of settlement state, not merely a log line.
- Queue timeouts must remain shorter than the connection retry window and longer than the slowest normal relay script.
- Never print environment values, private keys, wallet files, cookies, production service env files, or bot tokens while diagnosing an incident.

## Scheduled work that is correctness-critical

Some schedules preserve financial or product invariants rather than performing optional housekeeping. Examples include bridge relay and reservation release, prediction-market resolution within its contract window, product-analytics repair/sync, and monitoring sweeps. When changing a schedule, check the feature's tests and operational manual instead of treating it as a generic cron edit.

## Deployment boundary

This documentation describes how the repository is intended to behave. It does not authorize a production deployment, key rotation, payout, restart, or data deletion. Resolve exact targets, inspect current state, and follow the component runbook before a state-changing operation.
