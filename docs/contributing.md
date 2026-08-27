# Maintaining the Documentation

The documentation is source-controlled Markdown rendered by VitePress. The files under `docs/` remain readable on GitHub; the site adds navigation, full-text search, responsive layout, and stable URLs.

The production site is <https://docs.cyberia.church>. A production deployment builds the site inside the Laravel container, then nginx serves the generated files from a read-only bind mount. Content-only deployments replace that build in place; nginx is recreated only when its template or mount configuration changes.

## Information architecture

Write for one primary audience:

- `docs/user-guide/` — people using Cyberia applications and holding assets;
- `docs/developers/` — contributors and external integrators;
- `docs/operations/` plus the existing deep manuals — operators running the stack;
- `docs/growth/` and `docs/strategy/` — working artifacts, deliberately excluded from the published manual and search index.

Do not organize user documentation around controllers, composables, or repository folders. Start with the task the reader is trying to complete, then link to code only when it helps them verify or extend the behavior.

## Definition of done

A behavior change needs documentation when it changes any of the following:

- a user flow, supported network, fee, status, limitation, or security assumption;
- a public endpoint, request, response, model, quota, or authentication rule;
- a contract address, token decimal, deployment, or source of truth;
- an operator action, alert, schedule, runbook, or failure state;
- a setup command, prerequisite, build artifact, or verification command.

Update the relevant page in the same change as the code. Prefer a small correction to a new near-duplicate page.

## Writing rules

- Lead with the outcome and state who the page is for.
- Mark experimental, unavailable, trust-sensitive, or operator-controlled behavior plainly.
- Use exact network IDs, addresses, units, and status names where correctness depends on them.
- Give verification steps for claims about assets or settlement.
- Never include secrets or real private configuration values.
- Use relative links between documentation pages and run the production build to catch broken links.
- Date facts only when they are a snapshot; for live availability, point readers to the UI or a source of truth.

## Run locally

```bash
cd docs
npm install
npm run dev
```

The development server prints its local URL and reloads Markdown changes immediately.

## Validate

```bash
cd docs
npm run build
```

The build must finish without dead internal links. Generated files under `.vitepress/cache/` and `.vitepress/dist/` are ignored and must not be committed.

## Add a page

1. Put the Markdown file in the audience directory.
2. Add it to the relevant sidebar in `.vitepress/config.mts`.
3. Link it from the nearest overview page.
4. Build the site.
5. Check the mobile layout for wide tables and long code blocks.

VitePress file names become URLs. Use lowercase kebab-case for new files and avoid moving published pages without adding a redirect at the hosting layer.
