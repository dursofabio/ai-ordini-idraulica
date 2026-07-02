# Wiki — Sistema Catalogo Idraulica

Documentazione tecnica dell'applicativo, aggiornata allo stato del codice (non alle intenzioni originarie del PRD). Per la visione di prodotto completa (multicanale WhatsApp/email/audio) vedi [../PRD.md](../PRD.md) — **non ancora implementata**: quanto costruito finora copre solo il sotto-sistema di import, arricchimento e ricerca del catalogo prodotti.

## Indice

1. [Panoramica applicativo](01-panoramica.md) — cosa fa il sistema oggi, stack tecnico, differenza rispetto al PRD originale
2. [Architettura e modelli dati](02-architettura.md) — tabelle, modelli Eloquent, servizi applicativi, job, code
3. [Pannello Admin (Filament)](03-pannello-admin.md) — Resource, Review Queue, dashboard, comandi Artisan, scheduler
4. [Workflow: dal CSV/XLSX alla ricerca](04-workflow-import-ricerca.md) — guida step-by-step end-to-end

## Stato del progetto

Il backlog (`.archetipo/specs/US-001..US-028`) è quasi interamente in stato `REVIEW`. L'unica eccezione è **US-020 (API REST `GET /api/search`)**, ancora `PLANNED`: **l'endpoint di ricerca non esiste nel codice** (non c'è `routes/api.php`) e non esiste una UI Filament dedicata alla ricerca ibrida. Il motore di ricerca (`SearchService`) è funzionante ed è coperto da test, ma oggi è raggiungibile solo da codice/Tinker/test, non da un'interfaccia utente. Questo è documentato esplicitamente nel workflow.
