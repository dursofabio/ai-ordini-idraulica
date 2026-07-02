# Panoramica applicativo

## Cosa fa (oggi)

Il sistema gestisce il ciclo di vita del catalogo prodotti di un'azienda di forniture idrauliche, partendo da un export XLSX del gestionale (TeamSystem) e arrivando a un catalogo normalizzato, arricchito via AI e ricercabile semanticamente:

1. **Import** del file XLSX del catalogo in una tabella di staging, poi upsert idempotente sui prodotti reali.
2. **Normalizzazione deterministica**: riconoscimento marca (brand), estrazione attributi tecnici (kW, litri, DN, PN, bar, volt, watt, colore RAL, materiale...) tramite regex, clustering delle varianti dello stesso articolo commerciale in un "prodotto base", propagazione di famiglia/sottofamiglia tra le varianti.
3. **Arricchimento AI**: per i prodotti che la fase deterministica non riesce a classificare, un client Claude (Anthropic) classifica marca/famiglia/sottofamiglia a lotti, con cache, escalation a un modello più potente sui casi a bassa confidenza, e un tetto di spesa configurabile.
4. **Embedding vettoriale**: ogni "prodotto base" viene trasformato in un vettore (Ollama + `bge-m3`, 1024 dimensioni) salvato su Postgres/pgvector, per abilitare la ricerca semantica.
5. **Ricerca ibrida**: un `SearchService` fonde similarità vettoriale (pgvector) e ricerca full-text (Postgres `tsvector`/`ts_rank`) con filtri strutturati (marca, famiglia, attributi).
6. **Pannello Admin (Filament)**: revisione umana dei casi incerti ("Da revisionare"), gestione manuale delle anagrafiche, upload catalogo, dashboard con indicatori di copertura e costo AI.

## Cosa NON c'è ancora (visione PRD, non implementata)

Il [PRD](../PRD.md) originale descrive un sistema molto più ampio — ricezione richieste multicanale (WhatsApp, email, upload), trascrizione audio, OCR immagini, estrazione righe d'ordine strutturate, generazione PDF di prelievo, gestione stati ordine — pensato con uno stack Node/Python + React + Pinecone. **Nessuna di queste parti esiste nel codice attuale.** Quanto è stato realmente costruito (backlog US-001→US-028) è solo il motore di catalogo: import, normalizzazione, arricchimento, embedding, ricerca (backend) e pannello admin. È la fondazione su cui il flusso "richiesta cliente → matching → ordine" dovrà essere costruito in futuro.

Anche all'interno dello scope realizzato, un pezzo manca: **l'esposizione della ricerca** (endpoint API `GET /api/search` e/o UI Filament dedicata) è ancora `PLANNED` (US-020) — vedi [04-workflow-import-ricerca.md](04-workflow-import-ricerca.md).

## Stack tecnico reale

| Livello | Tecnologia |
|---|---|
| Backend | Laravel 13, PHP 8.3 |
| Admin UI | Filament v5, Livewire v4 |
| Codе asincrone | Laravel Horizon v5 (Redis) |
| Database | PostgreSQL + estensione `pgvector` |
| Parsing XLSX | `spatie/simple-excel` |
| Classificazione AI | Anthropic Claude (Messages API), modello "fast" (Haiku) + escalation "smart" (Sonnet) |
| Embedding | Ollama self-hosted, modello `bge-m3` (1024 dim) — **non Voyage AI** nonostante il titolo storico dello spec US-017 |
| Test | PHPUnit |

## Entità di dominio principali

- **Brand / Family / Subfamily** — anagrafiche/tassonomia, con alias per il matching testuale.
- **Product** — il singolo SKU del gestionale (codice articolo, costo, giacenza, stato di arricchimento).
- **ProductBase** — il "prodotto commerciale" che raggruppa le varianti (taglie/modelli) di un `Product`; è l'unità su cui viene calcolato l'embedding.
- **ProductAttribute** — attributo tecnico EAV (chiave/valore/unità) estratto per singolo `Product`.
- **ImportBatch / StagingArticolo** — tracciamento di ogni import e righe grezze in staging prima della promozione a `Product`.
- **ProductEmbedding** — vettore associato a un `ProductBase`.
- **EnrichmentLog** — audit di ogni chiamata AI (input/output/confidence/costo).

Dettagli di schema e relazioni in [02-architettura.md](02-architettura.md).
