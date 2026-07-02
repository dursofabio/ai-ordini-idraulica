# Pannello Admin (Filament), comandi Artisan e scheduler

## Resource Filament (`app/Filament/Resources/`)

- **ProductResource** — form (`ProductForm`) con `codice_articolo`/`description_raw` disabilitati (dati di sola lettura dal gestionale), Select per brand/family/subfamily con badge "🔒 Impostato manualmente" quando `source='manual'`. Table (`ProductsTable`) filtrabile per `enrichment_status`, brand, family. `canCreate()` disabilitato — i prodotti nascono solo da import.
- **ProductBaseResource** — table con azione custom **"Rigenera embedding"** che dispatcha `GenerateProductBaseEmbeddingJob` on-demand. Creazione ed eliminazione disabilitate (i prodotti-base nascono solo dal clustering).
- **BrandResource / FamilyResource / SubfamilyResource** — CRUD standard nel gruppo di navigazione "Anagrafiche", per la gestione manuale di nomi e alias.

## Coda di revisione ("Da revisionare")

Page dedicata `app/Filament/Pages/ReviewQueue.php`, non una tab di una Resource. Mostra `Product::where('enrichment_status', 'needs_review')` con tre azioni per riga:

| Azione | Effetto |
|---|---|
| **Conferma** | imposta `enrichment_status = 'enriched'` senza modificare i valori proposti |
| **Correggi** | form inline (Select brand/family/subfamily) → al salvataggio imposta `source='manual'`, `confidence=100`, `enrichment_status='enriched'` |
| **Scarta** | azzera brand/family/subfamily e le relative `source` (tranne i campi già `manual`), il prodotto resta `needs_review` per una futura riclassificazione |

## Upload catalogo

Page `app/Filament/Pages/ImportCatalog.php`, azione "Carica catalogo": `FileUpload` limitato a `.xlsx`, max 20 MB. Al submit chiama `ImportBatchService::startImport()` e accoda la chain `ImportXlsxJob → PromoteStagingToProductsJob` (la stessa usata dal comando CLI e dallo scheduler). Non è una vera progress bar: è una tabella degli `ImportBatch` con `poll('5s')` che aggiorna stato e contatori mentre i job girano in background su Horizon.

## Dashboard — widget

Tutti in `app/Filament/Widgets/`, tipo `StatsOverviewWidget`, polling ogni 15s:

- **CatalogCoverageWidget** — % di prodotti con brand/family/subfamily assegnati.
- **AiCostWidget** — costo stimato dell'ultimo batch di classificazione AI (da `EnrichmentLog` + `AiSpendGuard`).
- **InactiveProductsWidget** — conteggio prodotti con `is_active = false`.
- **EnrichmentStatusWidget** — conteggio per ciascun valore di `enrichment_status`.

## Comandi Artisan (`app/Console/Commands/`)

| Comando | Firma | Cosa fa |
|---|---|---|
| `catalog:import` | `{path} {--wait} {--timeout=}` | Avvia import di un file XLSX + job chain; `--wait` esegue polling (ogni 2s, timeout default 3600s) fino a stato terminale |
| `catalog:import-if-changed` | *(nessuna opzione)* | Legge `config('catalog.watch_path')`; importa solo se l'hash del file è cambiato rispetto all'ultimo batch completato |
| `catalog:enrich` | `{--only=}` | Esegue prima la normalizzazione deterministica (chunk da 500), poi accoda la classificazione AI a lotti; `--only=pending` limita ai soli prodotti non ancora processati |
| `catalog:embed` | `{--missing}` | Richiede `--missing`: dispatcha `GenerateProductBaseEmbeddingJob` per ogni `ProductBase` senza embedding |
| `catalog:reindex` | *(nessuna opzione)* | Ricostruisce l'indice full-text GIN su `search_vector` (**non** tocca gli embedding) |
| `catalog:seed-taxonomy` | *(nessuna opzione)* | Genera Brand/Family/Subfamily a partire dai valori distinti in staging (chunk da 2000), unendo gli alias |

## Scheduler (`bootstrap/app.php`, Laravel 13 — nessun `Kernel.php`)

```php
$schedule->command('catalog:import-if-changed')
    ->cron('*/' . config('catalog.schedule_frequency_minutes') . ' * * * *')
    ->withoutOverlapping();

$schedule->command('catalog:embed', ['--missing' => true])
    ->daily()
    ->withoutOverlapping();
```

- **Reimport automatico**: ogni N minuti (default 15, `CATALOG_SCHEDULE_FREQUENCY_MINUTES`), se il file sorgente è cambiato.
- **Embedding notturno**: una volta al giorno, genera gli embedding mancanti.

`routes/console.php` contiene solo il comando builtin `inspire`; nessun altro scheduling è definito lì.

## Code e Horizon

`config/horizon.php`: un supervisor su connessione `redis`, code `[import, enrich, embed, default]`, bilanciamento `auto` (scaling per tempo di attesa). `maxProcesses`: 1 in default/locale, 10 in produzione. La connessione di coda di default (`config/queue.php`) è `database`, non `redis`, salvo override esplicito di `QUEUE_CONNECTION`.
