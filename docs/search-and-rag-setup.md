# Search and AI Analysis Setup

This guide captures the full set of steps required to enable Elasticsearch-backed search and Retrieval-Augmented Generation (RAG) features for AI-AMS. Move through each section in order when preparing a new environment.

## 1. Provision Elasticsearch

1. Add the following variables to your `.env` file:
   ```ini
   ELASTICSEARCH_HOST=http://localhost:9200
   ELASTICSEARCH_INDEX=aiams_documents
   ELASTICSEARCH_USERNAME=
   ELASTICSEARCH_PASSWORD=
   ELASTICSEARCH_TIMEOUT=5
   ```
2. Start a local Elasticsearch node. For quick experiments, Docker works well:
   ```bash
   docker run --rm -p 9200:9200 \
     -e "discovery.type=single-node" \
     -e "xpack.security.enabled=false" \
     docker.elastic.co/elasticsearch/elasticsearch:8.15.0
   ```
3. Clear any cached configuration so Laravel can read the new settings:
   ```bash
   php artisan config:clear
   ```
4. Documents will be indexed automatically when they are created or updated. To re-index existing records manually run:
   ```bash
   php artisan search:reindex
   ```

## 2. Configure the language model and embeddings provider

1. Supply API credentials for the chat and embedding models you intend to use. The defaults target OpenAI-compatible APIs:
   ```ini
   OPENAI_API_KEY=sk-...
   OPENAI_BASE_URL=https://api.openai.com/v1
   OPENAI_CHAT_MODEL=gpt-4o-mini
   OPENAI_EMBEDDING_MODEL=text-embedding-3-small
   OPENAI_TEMPERATURE=0.2
   ```
2. When keys are missing the system falls back to deterministic, local responses. This allows functional UI tests without calling an external service.

## 3. Understand the processing workflow

1. **Chunking & embeddings** – whenever document text changes the app creates overlapping text chunks, computes embeddings, and stores results in the `document_chunks` table for reuse.
2. **Search indexing** – the save action also updates the Elasticsearch index so full-text and faceted queries can be served through the cluster.
3. **Summaries & analysis** – the "Generate draft summary" button and the "Ask a question" form call the RAG pipeline. Retrieved chunks are passed to the language model and responses are logged as document activity records for traceability.

If Elasticsearch or the LLM is unavailable, the UI gracefully falls back to database queries and heuristic summaries so you can continue working while infrastructure issues are resolved.
