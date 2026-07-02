# Workflow: dal file XLSX/CSV alla ricerca

Guida pratica, passo per passo, del flusso completo: dal caricamento del catalogo fino alla ricerca di un prodotto. Ogni step indica **cosa fare** (comando/azione UI) e **cosa succede internamente**.

> Nota sul formato file: nonostante nel PRD/backlog si parli genericamente di "CSV", il parser realmente implementato (`spatie/simple-excel`) legge **file XLSX**. Le colonne attese sono `codice_articolo`, `descrizione`, `costo_un_1` (→ `costo`), `giac_att_1` (→ `giacenza`); gli header vengono normalizzati automaticamente (lowercase + snake_case).

---

## Step 1 — Caricare il catalogo

**Come farlo (due modi equivalenti):**

- **UI**: pannello Filament → pagina "Importa catalogo" (`app/Filament/Pages/ImportCatalog.php`) → azione "Carica catalogo" → seleziona un file `.xlsx` (max 20 MB).
- **CLI**: `php artisan catalog:import storage/app/catalogo.xlsx --wait`
  (`--wait` fa il polling ogni 2s e ti restituisce l'esito finale invece di tornare subito al prompt).
- **Automatico**: lo scheduler esegue `catalog:import-if-changed` ogni N minuti (default 15, `CATALOG_SCHEDULE_FREQUENCY_MINUTES`) sul file indicato da `CATALOG_WATCH_PATH` — importa solo se l'hash del file è cambiato dall'ultimo import completato.

**Cosa succede internamente:**

1. `ImportBatchService::startImport()` calcola l'hash MD5 dell'intero file.
2. Se esiste già un `ImportBatch` **completato** con lo stesso hash → l'import viene rifiutato (`DuplicateImportException`): stai ricaricando un file identico a uno già importato.
3. Altrimenti crea un nuovo `ImportBatch` (stato `Uploaded`) e accoda la chain di job `ImportXlsxJob → PromoteStagingToProductsJob` sulla coda `import`.

**Dove verificare lo stato:** tabella `ImportBatch` nella pagina "Importa catalogo" (si aggiorna da sola ogni 5s), oppure `php artisan horizon` per vedere i job in esecuzione.

---

## Step 2 — Lettura del file in staging

**Cosa fare:** nulla — è automatico, parte dalla chain avviata allo Step 1.

**Cosa succede internamente (`ImportXlsxJob`):**

1. Stato batch → `Importing`.
2. Il file viene letto **a chunk di 1000 righe** (per non saturare la memoria su 42.600+ articoli).
3. Ogni riga senza `codice_articolo` viene scartata e conteggiata in `skipped_rows`.
4. Le righe valide vengono inserite in blocco (bulk insert) nella tabella `staging_articoli`, legate all'`ImportBatch` corrente.
5. A fine lettura: contatori aggiornati sul batch, stato → `Enriching`.

---

## Step 3 — Upsert idempotente in `products`

**Cosa fare:** nulla — automatico (`PromoteStagingToProductsJob`, in chain dopo lo Step 2).

**Cosa succede internamente:**

1. Le righe di staging vengono lette a chunk di 1000; per ciascun chunk una singola query recupera i `Product` esistenti con lo stesso `codice_articolo` (nessun N+1).
2. Per ogni riga si distingue: prodotto **nuovo o con descrizione cambiata** → il suo `enrichment_status` viene (ri)portato a `pending` (deve essere riclassificato); prodotto **invariato** → si aggiornano solo costo/giacenza/`is_active`, l'arricchimento esistente resta intatto.
3. Due query `upsert()` (una per gruppo) con chiave di conflitto `codice_articolo`.
4. `is_active` è `false` solo se `costo == 0` e `giacenza <= 0`.
5. A fine job: stato batch → `Completed`, notifica nel pannello Filament.

**Risultato atteso:** ogni articolo del file è ora una riga in `products`, con `enrichment_status = pending` se nuovo/modificato.

---

## Step 4 — Normalizzazione ed enrichment

**Come farlo:**

- **CLI**: `php artisan catalog:enrich` (oppure `--only=pending` per limitarsi ai soli prodotti non ancora processati).
- Nessuna azione manuale richiesta per l'esecuzione — ma **serve configurazione** prima del primo utilizzo (vedi sotto).

**Cosa succede internamente, in due fasi:**

### 4a — Normalizzazione deterministica (nessuna chiamata AI, gratuita)

Eseguita in chunk da 500, nell'ordine:

1. **`BrandResolver`** — cerca la marca nel suffisso della descrizione o per corrispondenza con nome/alias di un `Brand` esistente. Se trovata in modo univoco, imposta `brand_id` (`source='regex'` o `'dictionary'`).
2. **`AttributeResolver`** — estrae con regex attributi tecnici (kW, litri, pollici, DN, PN, bar, volt, watt, RAL, materiale) e li scrive in `product_attributes`.
3. **`GroupingResolver`** — solo se la marca è nota: normalizza la descrizione (rimuove il suffisso di taglia/modello), calcola una `grouping_key` deterministica e crea/riusa un `ProductBase` per quel gruppo.
4. **`FamilyPropagationResolver`** — propaga famiglia/sottofamiglia più frequente tra le varianti dello stesso `ProductBase` a quelle ancora prive di classificazione.

### 4b — Classificazione AI (solo per ciò che resta senza brand/family)

1. `ClassificationBatchDispatcher` seleziona i prodotti ancora `pending` senza brand/family, li raggruppa in lotti da 20–40 e li accoda come `ClassifyProductsBatchJob` (coda `enrich`) con un `runId` condiviso.
2. Per ogni lotto: verifica cache (stessa descrizione già classificata in passato?), verifica il tetto di spesa (`ANTHROPIC_BATCH_COST_CAP`), poi chiama il modello "fast" (Claude Haiku).
3. Se la confidence di un prodotto è `< 64`, viene rifatta una chiamata dedicata al modello "smart" (Claude Sonnet).
4. **Regola di scrittura finale** (`EnrichmentApplier`):
   - confidence `< 60` → nessun valore scritto, prodotto resta/torna `needs_review`;
   - confidence `60–84` → valori scritti, ma prodotto resta `needs_review` (va confermato a mano);
   - confidence `≥ 85` → `enrichment_status = 'enriched'`.
5. Ogni chiamata AI viene loggata in `EnrichmentLog` (input/output/confidence/token) — è la fonte dei dati del widget "Costi AI".

**Configurazione richiesta** (in `.env`, vedi `.env.example`): `ANTHROPIC_API_KEY`, eventualmente `ANTHROPIC_MODEL_FAST`/`ANTHROPIC_MODEL_SMART` e `ANTHROPIC_BATCH_COST_CAP` per limitare la spesa.

**Dove verificare il risultato:** pannello Filament → pagina **"Da revisionare"** → mostra tutti i `Product` con `enrichment_status = needs_review`, con azioni **Conferma** / **Correggi** / **Scarta** (vedi [03-pannello-admin.md](03-pannello-admin.md)).

---

## Step 5 — Generazione degli embedding

**Come farlo:**

- **Automatico**: alla creazione/modifica di `description_ai` su un `ProductBase` (es. dopo il grouping o dopo una correzione manuale di brand/family), `ProductBaseObserver` dispatcha subito `GenerateProductBaseEmbeddingJob`.
- **CLI (batch dei mancanti)**: `php artisan catalog:embed --missing`.
- **Automatico notturno**: lo scheduler esegue `catalog:embed --missing` una volta al giorno, per recuperare eventuali embedding rimasti indietro.
- **Manuale singolo**: in Filament, sulla lista dei "Prodotti base", azione **"Rigenera embedding"** su una riga specifica.

**Configurazione richiesta**: un'istanza Ollama raggiungibile (`EMBEDDING_BASE_URL`, default `http://localhost:11434`) con il modello `bge-m3` disponibile (`EMBEDDING_MODEL`).

**Cosa succede internamente:**

1. Il job prende `description_ai` del `ProductBase` (title + brand + family + subfamily).
2. Chiama l'API nativa di Ollama (`POST /api/embeddings`) → vettore a 1024 dimensioni.
3. Salva/aggiorna la riga in `product_embeddings` (chiave `[product_base_id, model]`), colonna `vector(1024)` su Postgres/pgvector.

**Nota**: `catalog:reindex` **non** genera embedding — ricostruisce solo l'indice full-text (`search_vector`) su `product_bases`. Usalo dopo un import massivo se noti che la ricerca full-text non riflette gli ultimi dati.

---

## Step 6 — Ricerca

**Stato attuale: non esiste ancora una UI o un endpoint API per la ricerca** (US-020 "API REST GET /api/search" è `PLANNED`, non implementata — nessun `routes/api.php` nel progetto). Il motore (`App\Services\Search\SearchService`) è completo e testato, ma oggi è utilizzabile solo da codice/Tinker:

```bash
php artisan tinker --execute '
    $results = app(App\Services\Search\SearchService::class)->search(
        "pompa 2CV",
        ["brand_id" => 3, "attributes" => [["key" => "potenza_kw", "min" => 1, "max" => 3]]]
    );
    foreach ($results as $r) {
        echo $r->productBase->title, " — varianti: ", $r->variantsCount, PHP_EOL;
    }
'
```

**Cosa succede internamente quando si chiama `search($query, $filters)`:**

1. Se `$query` combacia esattamente con un `codice_articolo` esistente, il `ProductBase` corrispondente viene forzato in cima ai risultati.
2. Vengono applicati i filtri strutturati opzionali: `brand_id`, `family_id`, `subfamily_id`, `attributes[]` (range numerico `min`/`max` o valore testuale esatto).
3. Il ranking dei risultati rimanenti è la somma pesata di:
   - **score vettoriale** (peso 70%, `SEARCH_WEIGHT_VECTOR`): `1 - cosine_distance(embedding_query, embedding_prodotto)` via pgvector (operatore `<=>`); l'embedding della query viene generato al volo (Ollama) e messo in cache (`SEARCH_QUERY_CACHE_TTL`, default 3600s);
   - **score full-text** (peso 30%, `SEARCH_WEIGHT_FTS`): `ts_rank` di Postgres su `search_vector` con `plainto_tsquery('italian', query)`.
4. Ogni riga risultato è un `ProductBase` (non il singolo SKU) arricchito con `variants_count` (quante varianti/taglie ha) e `power_range_min/max` (range di potenza kW tra le varianti) — utile per distinguere "stesso prodotto, taglie diverse" senza aprire ogni scheda.
5. **Fallback**: su un database non-Postgres (es. SQLite nei test) la ricerca degrada a un semplice `LIKE` su titolo/descrizione, senza fusione vettoriale.

**Prossimo passo per rendere la ricerca utilizzabile da un operatore**: implementare US-020 (endpoint `GET /api/search`) e/o una pagina Filament dedicata che chiami `SearchService::search()`.

---

## Riepilogo end-to-end

```
1. Upload XLSX (UI/CLI/scheduler)
        ↓
2. ImportXlsxJob        → righe grezze in staging_articoli
        ↓
3. PromoteStagingToProductsJob → upsert in products (chiave: codice_articolo)
        ↓
4. catalog:enrich       → 4a normalizzazione regex (brand, attributi, grouping, propagazione)
                        → 4b classificazione AI per i casi non risolti + coda "Da revisionare"
        ↓
5. catalog:embed / trigger automatico → vettore in product_embeddings (Ollama bge-m3)
        ↓
6. SearchService::search() → risultati ibridi (vettore 70% + full-text 30% + filtri)
                              [oggi richiamabile solo da codice: nessuna UI/API pubblica]
```
