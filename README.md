# AI-AMS MVP Support Files

This repository now includes a lightweight sample PDF you can use while testing the document upload workflow locally.

## Sample document

- **Path:** `resources/examples/sample-document.pdf`
- **Description:** Single-page placeholder with the title “AI-AMS Sample Document”.

To try it out in the UI:

1. Start your local environment and run the storage link command if you have not already:
   ```bash
   php artisan storage:link
   ```
2. Upload the sample PDF through the document upload form.
3. Proceed with any metadata or summary steps required for your workflow.

Feel free to duplicate or rename the file as needed when testing additional flows.

## Enabling search and AI-powered analysis

The MVP can push document content into Elasticsearch and call an external LLM for summaries or Q&A once a few environment variables are supplied.

### 1. Configure Elasticsearch

Add these variables to your `.env` file:

```ini
ELASTICSEARCH_HOST=http://localhost:9200
ELASTICSEARCH_INDEX=aiams_documents
ELASTICSEARCH_USERNAME=
ELASTICSEARCH_PASSWORD=
ELASTICSEARCH_TIMEOUT=5
```

Start an Elasticsearch node (Docker example):

```bash
docker run --rm -p 9200:9200 -e "discovery.type=single-node" -e "xpack.security.enabled=false" docker.elastic.co/elasticsearch/elasticsearch:8.15.0
```

After updating `.env`, rebuild the config cache (`php artisan config:clear`). Documents are indexed automatically when they are created or updated. You can re-sync everything manually with:

```bash
php artisan search:reindex
```

### 2. Configure the LLM + embeddings provider

Provide API credentials for the models you want to use (defaults assume OpenAI-compatible endpoints):

```ini
OPENAI_API_KEY=sk-...
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_CHAT_MODEL=gpt-4o-mini
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
OPENAI_TEMPERATURE=0.2
```

When the keys are missing, the app still works with deterministic, local fallbacks so you can continue testing without external calls.

### 3. Understand the processing workflow

1. **Chunking & embeddings** – whenever a document’s text changes, the app creates overlapping text chunks and requests embeddings. Results are stored in the `document_chunks` table for reuse.
2. **Search indexing** – the same save action re-indexes the document in Elasticsearch so full-text and faceted queries are handled through the cluster.
3. **Summaries & analysis** – the “Generate draft summary” button or the new “Ask a question” form uses Retrieval-Augmented Generation (RAG) combining the stored chunks with your selected chat model. Outputs are logged as document activity entries for traceability.

If Elasticsearch or the LLM is unreachable, the UI gracefully falls back to database queries and heuristic summaries so the MVP remains usable.
