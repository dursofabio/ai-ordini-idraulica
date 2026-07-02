# Architettura e modelli dati

## Modelli Eloquent (`app/Models`)

### Anagrafiche

| Modello | Tabella | Campi chiave | Relazioni |
|---|---|---|---|
| `Brand` | `brands` | `name`, `slug` (unique), `aliases` (json) | referenziato via `brand_id` da `Product`/`ProductBase` |
| `Family` | `families` | `name`, `slug` (unique), `aliases` (json) | `subfamilies()` HasMany |
| `Subfamily` | `subfamilies` | `name`, `slug` (unique), `aliases` (json), `family_id` | `family()` BelongsTo |

Gli `aliases` (array JSON di sinonimi/varianti testuali) sono usati dal `BrandResolver` per il matching a dizionario.

### Catalogo

**`ProductBase`** (`product_bases`) — il "prodotto commerciale", nodo di clustering delle varianti:
- `title`, `description_ai` (testo composto per l'embedding), `grouping_key` (**unique**, hash deterministico)
- FK nullable `brand_id`/`family_id`/`subfamily_id`
- `search_vector`: colonna generata Postgres `tsvector` su `title || description_ai` (lingua `italian`)
- relazioni: `products()` HasMany, `embedding()` HasOne&lt;`ProductEmbedding`&gt;
- `composeDescriptionAi()`: concatena title + brand + family + subfamily nel testo da mandare in embedding

**`Product`** (`products`) — il singolo SKU del gestionale:
- `codice_articolo` (unique), `description_raw`, `description_clean`, `descrizione_marca`
- `costo`, `giacenza`, `is_active` (calcolato: `false` solo se costo == 0 e giacenza ≤ 0)
- `enrichment_status`: `pending` → `needs_review` | `enriched`
- FK `product_base_id`, `brand_id`, `family_id`, `subfamily_id`
- per ciascuna delle tre classificazioni, un campo `*_source` con provenienza: `regex` / `dictionary` / `propagated` / `ai` / `manual` / `file`
- `confidence` (0–100)
- relazioni: `productBase()`, `brand()`, `family()`, `subfamily()`, `attributes()` HasMany&lt;`ProductAttribute`&gt;, `enrichmentLogs()` HasMany&lt;`EnrichmentLog`&gt;

**`ProductAttribute`** (`product_attributes`) — modello EAV per attributo tecnico: `product_id`, `key`, `value_num`, `value_text`, `unit`, `source` (`regex`/`ai`/`manual`).

**`ProductEmbedding`** (`product_embeddings`) — vettore per `ProductBase`: `content`, `model`, `dimensions` (default 1024), `embedding` (`vector(1024)` pgvector, `text` fallback su altri driver), unique su `[product_base_id, model]`, indice HNSW cosine.

### Import e audit

**`ImportBatch`** (`import_batches`) — un import = una riga: `filename`, `hash` (MD5 dell'intero file, usato per il dedup), `status` (enum `App\Enums\ImportBatchStatus`), contatori (`total_rows`, `processed_rows`, `error_rows`, `skipped_rows`, `rows_new`, `rows_updated`), `started_at`/`finished_at`.

**`StagingArticolo`** (`staging_articoli`) — riga grezza importata prima della promozione: `import_batch_id`, `raw_row` (json), `codice_articolo`, `descrizione`, `costo`, `giacenza`, `status`.

**`EnrichmentLog`** (`enrichment_logs`) — audit di ogni step di arricchimento AI: `product_id`, `step`, `input`/`output` (json), `confidence`, `model`, `tokens_in`/`tokens_out`. Usato anche per stimare i costi AI nella dashboard.

> Non esiste una tabella/modello "review queue" dedicato: la coda di revisione è semplicemente `Product::where('enrichment_status', 'needs_review')`.

## Servizi applicativi (`app/Services`)

### Import (`app/Services/ImportBatchService.php`)
Orchestratore del ciclo di vita di un `ImportBatch`: `startImport()` calcola l'hash del file, verifica duplicati (`DuplicateImportException` se un batch con lo stesso hash è già `Completed`), crea il batch e ne gestisce le transizioni di stato (`Uploaded → Importing → Enriching → Completed`, con `Failed` raggiungibile dagli stati intermedi).

### Normalizzazione deterministica (`app/Services/Enrichment/`)
Orchestrata da `DeterministicEnrichmentPipeline::run()`, in ordine fisso:

1. **`BrandResolver`** — regex sul suffisso (`"... - MARCA -"`) o matching a dizionario/alias su parola intera; assegna `source='regex'` (confidence 95) o `'dictionary'` (confidence 80); salta se ambiguo (0 o 2+ match).
2. **`AttributeResolver`** — estrattori regex per kW, litri, pollici, DN, PN, bar, volt, watt, RAL, materiale; scrivono `ProductAttribute` con `source='regex'`.
3. **`GroupingResolver`** — normalizza la descrizione (rimuove suffissi di taglia), calcola `grouping_key = sha256(brand_id|descrizione_normalizzata)`, fa `firstOrCreate` su `ProductBase`.
4. **`FamilyPropagationResolver`** — solo se il grouping ha avuto successo: propaga famiglia/sottofamiglia prevalente tra le varianti dello stesso `ProductBase` a quelle ancora senza valore, con `source='propagated'`.

### Arricchimento AI (`app/Services/Ai/`, `app/Services/Enrichment/`)
- **`ClaudeClient`** — client HTTP per Anthropic Messages API (`messages()`) e Batch API (`batch()`, definita ma non usata da alcun job attivo).
- **`ClassificationPromptBuilder`** — costruisce il prompt con tassonomia chiusa, richiede output JSON strict.
- **`ClassificationBatchDispatcher`** — seleziona prodotti `pending` senza brand/family, li raggruppa in lotti (20–40) e dispatcha `ClassifyProductsBatchJob` con un `runId` condiviso.
- **`ClassifyProductsBatchJob`** — per ogni lotto: dedup per hash descrizione, check cache, check tetto di spesa, chiamata al modello "fast" (`claude-3-5-haiku-latest`), escalation al modello "smart" (`claude-sonnet-4-5`) se `confidence < 64`.
- **`EnrichmentApplier`** — regole di scrittura in base alla confidence: `< 60` → nulla scritto, `needs_review`; `60–84` → valori scritti ma resta `needs_review`; `≥ 85` → `enriched`. Non sovrascrive mai campi `source='manual'`.
- **`EnrichmentCache`** — cache (via `Cache` facade) chiave `enrichment:classification:{sha256(description_raw)}`.
- **`AiSpendGuard`** — tetto di spesa configurabile per run, tracciato in cache con lock; oltre soglia, i prodotti restanti vanno direttamente in `needs_review`.

### Embedding (`app/Services/Ai/EmbeddingClient.php`)
Client HTTP per l'API nativa di Ollama (`POST /api/embeddings`, body `{model, prompt}`) — **non** lo schema OpenAI-style di Voyage AI. Config in `config/services.php` (`embedding.base_url`, `embedding.model=bge-m3`, `embedding.dimensions=1024`).

### Ricerca (`app/Services/Search/SearchService.php`)
Vedi il dettaglio della formula di fusione in [04-workflow-import-ricerca.md](04-workflow-import-ricerca.md#5-ricerca).

## Job e code (Horizon)

| Job | Coda | Retry/timeout | Trigger |
|---|---|---|---|
| `ImportXlsxJob` | `import` | tries=1, timeout=3600 | upload/CLI/scheduler |
| `PromoteStagingToProductsJob` | `import` | tries=1, timeout=3600 | in chain dopo `ImportXlsxJob` |
| `ClassifyProductsBatchJob` | `enrich` | tries=1, timeout=600 | `ClassificationBatchDispatcher` (via `catalog:enrich`) |
| `GenerateProductBaseEmbeddingJob` | `embed` | tries=3 | `ProductBaseObserver`, azione manuale Filament, `catalog:embed --missing`, scheduler notturno |

`config/horizon.php`: un supervisor su Redis, code `[import, enrich, embed, default]`, bilanciamento `auto`. La connessione di coda di default è `database` (non Redis) salvo override `QUEUE_CONNECTION`.

## Diagramma component-level

```
XLSX upload/CLI/scheduler
        │
        ▼
 ImportBatchService (hash+dedup, stato batch)
        │
        ▼
 ImportXlsxJob ── staging_articoli
        │
        ▼
 PromoteStagingToProductsJob ── upsert su products (chiave: codice_articolo)
        │
        ▼
 catalog:enrich
        ├─ DeterministicEnrichmentPipeline (Brand → Attribute → Grouping → FamilyPropagation)
        └─ ClassificationBatchDispatcher → ClassifyProductsBatchJob (Claude) → EnrichmentApplier
        │
        ▼
 ProductBaseObserver → GenerateProductBaseEmbeddingJob (Ollama bge-m3) → product_embeddings (pgvector)
        │
        ▼
 SearchService (vettoriale 70% + full-text 30% + filtri) — oggi solo backend, nessuna UI/API (US-020 PLANNED)
```
