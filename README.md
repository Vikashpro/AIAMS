# AI-AMS MVP Notes

This repository extends the original Laravel real-estate starter into an archival management MVP. Use the references below when setting up a local environment or demo.

## Quick start

1. Install Composer and NPM dependencies.
2. Copy `.env.example` to `.env`, configure your database, and run migrations with seeders.
3. Link storage with `php artisan storage:link` and start the dev servers (`php artisan serve`, `npm run dev`).

## Test sample document

A single-page PDF is available for quick ingestion tests:

- **Path:** `resources/examples/sample-document.pdf`
- **Usage:** Upload through the Documents → New page and apply any metadata or manual summary steps you want to try.

## Search + RAG setup

Detailed instructions for Elasticsearch, embeddings, and LLM configuration now live in [`docs/search-and-rag-setup.md`](docs/search-and-rag-setup.md). Moving the setup guide out of this file keeps the README short—which should reduce merge conflicts—while preserving all of the necessary operational detail.

## Need to sync with upstream?

If `git pull` warns about local README edits, either commit your changes or stash them before pulling. The concise README plus the dedicated docs folder should make future conflicts less likely.
