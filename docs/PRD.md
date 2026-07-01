# Sistema di Trasformazione Richieste in Ordini e Preventivi - Documento dei Requisiti di Prodotto

**Autore:** ARchetipo  
**Data:** 25 Giugno 2026  
**Versione:** 1.0

---

## Elevator Pitch

> Per **Alberto e il team commerciale**, che hanno il problema di **trasformare manualmente 42.600 articoli in 15 minuti per richiesta**, **Sistema di Trasformazione Richieste** è un **sistema interno di automazione del flusso di ricezione ordini** che **riduce il tempo da messaggio a lista magazzino da 15 minuti a meno di 3 minuti, con matching intelligente e revisione umana**. A differenza di **trascrizione manuale**, il nostro prodotto **integra OCR, trascrizione audio, estrazione strutturata e matching semantico con il catalogo**, mantenendo il controllo qualità nelle mani dell'operatore.

---

## Visione

Il sistema trasforma ogni richiesta ricevuta via WhatsApp, email o allegati (testo, immagini, audio) in una bozza strutturata di ordine o preventivo, riducendo significativamente il lavoro manuale e gli errori di trascrizione. Crea un ponte tra i clienti e il gestionale interno (TeamSystem), dove l'operatore commerciale verifica e valida, il magazziniere prelevaste la lista, e l'amministrazione inserisce l'ordine finale.

### Differenziale del Prodotto

1. **Matching intelligente 3-opzioni**: non propone "la risposta giusta", ma le 3 più probabili, riducendo il rischio di errori e permettendo revisione veloce
2. **Multimodale nativo**: gestisce contemporaneamente testo, immagini (foto lista cliente), audio (registrazioni vocali), PDF — e trasforma tutto in dati strutturati
3. **Storico completo**: ogni richiesta ha uno storico tracciabile, allegati, bozze, correzioni — riduce "dove l'ho messo?"
4. **Basato su 42.600 articoli reali**: non generico, ma sintonizzato sul catalogo idraulico specifico della azienda

---

## Personas Utente

### Persona 1: Alberto, Operatore Commerciale

**Ruolo:** Operatore commerciale senior  
**Età:** 34 anni | **Background:** 15 anni in azienda, conosce a fondo il catalogo e i clienti, uso digital minimo

**Obiettivi:**
- Ridurre il tempo di trascrizione manuale
- Evitare errori di trascrizione che generano richieste di chiarimento
- Avere traccia di ogni richiesta e della versione finale approvata
- Fare priorità su richieste urgenti

**Criticità:**
- Trascrizione manuale da WhatsApp/email è lenta (15 min per richiesta)
- Ricerca mentale del codice articolo è soggetta a errori (cliente dice "compressore 2CV", esiste come 4 modelli diversi)
- Quando il cliente si lamenta della lista magazzino, Alberto non sa se è stato lui a sbagliatotrasmissione o il magazziniere
- Le richieste audio e foto vanno trascritte a mano — niente automazione

**Comportamenti & Strumenti:**
- Lavora con WhatsApp, email e telefono
- Scrive brief su carta o blocco notes
- Accede quotidianamente al portale TeamSystem
- Legge e stampa preventivi PDF
- Non usa scorciatoie da tastiera, teme i tool tecnici

**Motivazioni:** Ridurre stress, finire prima le 5–10 richieste al giorno, avere feedback positivi dai clienti

**Familiarità Tecnologica:** Media (uso browser, email, WhatsApp, poco oltre)

#### Customer Journey — Alberto

| Fase | Azione | Pensiero | Emozione | Opportunità |
|---|---|---|---|---|
| **Consapevolezza** | Riceve richiesta WhatsApp "mi serve un compressore" + foto | "Inizia la trascrizione..." | Rassegnazione | Sistema potrebbe riconoscere il prodotto dalla foto |
| **Valutazione** | Legge il messaggio, cerca mentalmente i codici articoli, verifica catalogo su carta o sistema | "Quale modello? Ci sono 3 varianti..." | Incertezza | Sistema potrebbe suggerire i 3 top match e Alberto decide in 10 sec |
| **Primo Uso** | Scrive su carta la lista prodotti, ricopia su TeamSystem | "Mi dimentico sempre il modello/colore..." | Frustrazione | Sistema potrebbe compilare la bozza automaticamente |
| **Uso Regolare** | Riceve 5–10 richieste al giorno, dedica 75–120 minuti di trascrizione | "Questo lavoro è ripetitivo..." | Monotonia | Sistema potrebbe ridurre a 10–20 minuti totali |
| **Advocacy** | Mostra al cliente la lista prelievo corretta, racconta come il sistema ha evitato errori | "Finalmente ho il tempo per altre cose" | Soddisfazione | Il cliente segnala i vantaggi in azienda |

---

### Persona 2: Marco, Responsabile Magazzino

**Ruolo:** Responsabile magazzino  
**Età:** 45 anni | **Background:** Esperienza lunga, gestisce 4 prelevatori, pragmatico

**Obiettivi:**
- Ricevere liste chiare e senza errori di trascrizione
- Gestire varianti, mancanti e urgenze chiaramente
- Ridurre richieste di chiarimento
- Tracciare cosa è stato prelevato e cosa no

**Criticità:**
- Riceve lista su carta, non sa se è prioritaria fino a che Alberto la non spiega verbalmente
- Quando "manca il codice" o la quantità è ambigua, must contattare Alberto
- La lista su carta si pierde, bagna, manomette — niente storico digitale
- Non distingue ordine da preventivo fino a che Alberto non lo spiega

**Comportamenti & Strumenti:**
- Legge liste di prelievo su carta (stampa)
- Comunica verbalmente con i prelevatori
- Qualche volta accede a email
- Evita sistemi software complicati

**Motivazioni:** Prelievi corretti al primo colpo, ridurre tempo di clarification, traccia di tutto

**Familiarità Tecnologica:** Bassa (carta + verbale preferibilmente)

#### Customer Journey — Marco

| Fase | Azione | Pensiero | Emozione | Opportunità |
|---|---|---|---|---|
| **Consapevolezza** | Riceve lista su carta da Alberto | "Cosa devo fare oggi?" | Neutralità | Sistema potrebbe inviare notifica con priorità |
| **Valutazione** | Legge la lista, identifica prodotti, controlla giacenza | "Questo è urgente? Normale?" | Confusione su priorità | Sistema potrebbe marcare urgenze e destinazioni |
| **Primo Uso** | Distribuisce lista ai prelevatori, monitora loro | "Se manca qualcosa, chi chiamo?" | Incertezza | Sistema potrebbe tracciare mancanti e suggerire sostituzioni |
| **Uso Regolare** | Gestisce 10–15 liste al giorno, nota errors nei dati ricevuti | "Il codice è sbagliato di nuovo..." | Irritazione | Sistema potrebbe validare e suggerire correzioni a Alberto |
| **Advocacy** | Vede che errori diminuiscono, prelievi sono più veloci | "Finalmente capisco cosa fare" | Sollievo | Marco conferma i vantaggi al capo |

---

## Insights da Brainstorming

### Assunzioni Messe in Discussione

🧭 **Costanza**: "E se NON usassimo AI per il primo matching, ma offrissimo tre opzioni invece di una? Alberto le vede, sceglie in 10 secondi, riduce il rischio di errore."  
→ **Scoperta**: Matching a 3 opzioni è meglio di matching "perfetto-o-niente". La revision umana risolve i borderline.

🧭 **Costanza**: "E se Alberto ricevesse audio diretto, non carta trascritto? Il sistema trascrive e genera una bozza in 20 secondi."  
→ **Scoperta**: La trascrizione audio elimina il collo di bottiglia #1 (15 minuti di trascrizione manuale).

🧭 **Costanza**: "E se il magazzino usasse QR code invece di carta?Marca urgenza, destinazione, mancanti — il prelevatore scannerizza e sa subito."  
→ **Scoperta**: QR e stampa possono coesistere. Start con carta, upgrade a QR in V2.

### Nuove Direzioni Scoperte

1. **Priorità negoziale**: le richieste urgenti / ripetitive meritano "fast lane" (match salvati + autocomplete)
2. **Catalogo dinamico**: il file Excel attuale va versioned — permette di tracciare "il cliente chiedeva articolo X, ora è Y"
3. **Archivio richieste**: diventa assets commerciale — "guarda, questo cliente ha sempre ordinato questi 10 articoli"

---

## Scope del Prodotto

### MVP - Prodotto Minimo Realizzabile

**Capacità core:**

1. **Ricezione multimodale**
   - WhatsApp (webhook parser)
   - Email (IMAP parser, forwarding)
   - Upload manuale (file, immagini, audio)

2. **Elaborazione dei contenuti**
   - Trascrizione audio (Whisper)
   - OCR immagini (Tesseract / DocumentAI)
   - Estrazione righe strutturate (LLM prompt-based o no-code extractor)
   - Parametri estratti: descrizione, quantità, U.M., note, urgenza

3. **Matching catalogo**
   - Connessione al file Excel / export TeamSystem
   - Semantic search + fuzzy match
   - Ritorna: top-3 suggerimenti (codice, descrizione gestionale, U.M., confidence, alternative)

4. **Revisione manuale**
   - Interfaccia web: visualizza bozza, permette correzione righe, selezione del match, note aggiuntive
   - Salva versione finale

5. **Generazione list prelievo**
   - PDF stampabile (carta)
   - Tabella: codice, descrizione, quantità, U.M., note, urgenza
   - QR code opzionale con link a richiesta

6. **Archivio e storico**
   - Ogni richiesta ha: data, cliente, allegati originali, bozze, versione finale
   - Ricerca per cliente, data, stato

### Feature di Crescita (Post-MVP)

- Inserimento automatico ordini in TeamSystem (quando match è confermato)
- Aggiornamento automatico giacenze
- Calcolo prezzi e totali
- Notifiche WhatsApp al cliente ("ordine ricevuto", "prelevato")
- App mobile per magazzino (lista interattiva, scanning barcode)
- Analitiche: tempo medio richiesta → lista, % match corretti, clienti top

### Visione (Futuro)

- Gestione completa barcode e palmari magazzino
- Integrazione CRM (cliente → storico ordini)
- Previsioni e suggerimenti ("questo cliente di solito ordina anche...")
- Mobile app dedicata per operatore commerciale e magazzino
- Integrazione ERP avanzata (sincronizzazione giacenze real-time, fatturazione)

---

## Architettura Tecnica

> **Proposto da:** 📐 Leonardo (Architect)

### Architettura di Sistema

```
┌─────────────────────────────────────────────────────────────┐
│                      Ricezione Richieste                      │
│  ┌──────────────┬──────────────┬──────────────┐              │
│  │   WhatsApp   │     Email    │   Upload     │              │
│  │   Webhook    │   (IMAP)     │   Manuale    │              │
│  └──────────────┴──────────────┴──────────────┘              │
└──────────────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                    Elaborazione Contenuti                    │
│  ┌──────────────┬──────────────┬──────────────┐              │
│  │ Trascrizione │      OCR     │  Estrazione  │              │
│  │   (Whisper)  │ (Tesseract)  │     (LLM)    │              │
│  └──────────────┴──────────────┴──────────────┘              │
└──────────────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                    Matching Catalogo                         │
│  ┌──────────────┬──────────────┐                             │
│  │   Semantic   │    Fuzzy     │                             │
│  │   Search     │    Match     │ → Top-3 Suggerimenti       │
│  └──────────────┴──────────────┘                             │
└──────────────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│               Revisione Manuale (Web UI)                     │
│               ↓ Alberto corregge e valida                    │
└─────────────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│          Generazione e Archiviazione                         │
│  ┌──────────────┬──────────────┬──────────────┐              │
│  │     PDF      │   Storico    │   Notifiche  │              │
│  │  Prelievo    │  Richiesta   │   (Future)   │              │
│  └──────────────┴──────────────┴──────────────┘              │
└─────────────────────────────────────────────────────────────┘
```

**Pattern Architetturale:** Event-driven processing + human-in-the-loop validation

**Componenti Principali:**
- **API Gateway**: riceve richieste multimodale, invia a queue
- **Worker di Elaborazione**: processa audio/OCR/estrazione, salva draft
- **Matching Engine**: semantic search + fuzzy fallback
- **Review Service**: API per web UI, salva versione validated
- **Storage**: PostgreSQL (metadati), object storage (allegati)
- **Frontend**: React SPA per review interface

### Technology Stack

| Layer | Tecnologia | Versione | Razionale |
|---|---|---|---|
| Linguaggio Backend | Node.js o Python | 18+ / 3.11+ | Ecosystem OCR/trascrizione maturo; flexibility |
| Framework Backend | Express.js o FastAPI | - | Minimal, focused; facile integrazione async |
| Frontend | React | 18+ | SPA interattiva, revision interface |
| Database | PostgreSQL | 14+ | ACID, JSONB per metadati flessibili, full-text search |
| ORM | Prisma o SQLAlchemy | - | Type-safe, migrations automatiche |
| Auth | JWT + middleware | - | Stateless, supporta integrazione interna |
| OCR | Tesseract (OSS) o Google DocumentAI | - | Tesseract per cost, DocumentAI per accuracy |
| Trascrizione | OpenAI Whisper API | - | Accurato, multilingue, serverless |
| Matching | Pinecone (embeddings) + fuzzy (FuzzyWuzzy) | - | Semantic + fallback, scalabile |
| Testing | Jest (Node.js) / Pytest (Python) | - | Unit + integration + e2e |
| Containerization | Docker | - | Deploy isolato, CI/CD |

### Struttura del Progetto

**Pattern Organizzativo:** Modular monolith → microservices later

```
request-transformer/
├── backend/
│   ├── src/
│   │   ├── api/              # Express/FastAPI routes
│   │   ├── services/         # Business logic
│   │   │   ├── transcription/
│   │   │   ├── ocr/
│   │   │   ├── extraction/
│   │   │   ├── matching/
│   │   │   └── archival/
│   │   ├── models/           # DB models (Prisma/SQLAlchemy)
│   │   ├── utils/
│   │   └── config/
│   ├── tests/
│   ├── docker-compose.yml
│   └── package.json / requirements.txt
│
├── frontend/
│   ├── src/
│   │   ├── components/       # React components
│   │   │   ├── ReviewPanel/
│   │   │   ├── MatchingResults/
│   │   │   └── ArchiveSearch/
│   │   ├── pages/
│   │   ├── services/         # API calls
│   │   └── styles/
│   ├── public/
│   └── package.json
│
├── docs/
│   ├── PRD.md
│   ├── ADR/
│   └── mockups/              # UI references
│
├── .archetipo/
│   ├── config.yaml
│   ├── backlog.yaml (soon)
│   └── plans/ (soon)
│
└── README.md
```

### Ambiente di Sviluppo

**Requisiti**:
- Node.js 18+ o Python 3.11+
- PostgreSQL 14+ (locale con Docker)
- Docker & Docker Compose
- Postman / Insomnia (API testing)
- GitHub CLI (`gh`)

**Setup locale**:
```bash
# Backend
cd backend
npm install        # o pip install -r requirements.txt
npm run dev        # dev server with hot reload

# Frontend
cd frontend
npm install
npm start          # React dev server :3000

# Database
docker compose up  # PostgreSQL + migrations
```

**Accesso API**: `http://localhost:5000` (backend), `http://localhost:3000` (frontend)

### CI/CD & Deployment

**Build Tool**: GitHub Actions (CI), Docker (containerization)

**Pipeline**:
1. **Push branch** → GitHub Actions runs:
   - Linting (ESLint / Pylint)
   - Unit + integration tests
   - Security scan
2. **PR approved** → merge to `main` → Docker image built
3. **Docker image** → pushed to registry
4. **Deployment**: 
   - Staging: auto-deploy su merge
   - Production: manual trigger (o scheduled nightly)

**Deploy Strategy**: Docker containers + env config

**Infrastruttura Target**: 
- **V1**: Cloud (AWS EC2 + RDS oppure GCP Cloud Run)
- **Vincolo**: nessuno al momento — scegli flessibile

### Architecture Decision Records (ADR)

1. **ADR-001: Semantic Search + Fuzzy Fallback**
   - Decisione: usare Pinecone per embeddings semantici, fallback a fuzzy match
   - Razionale: 42.600 articoli richiedono ricerca intelligente; fuzzy gestisce typo
   - Trade-off: costo Pinecone vs. latenza

2. **ADR-002: Human Review Before Archive**
   - Decisione: tutte le richieste passano per review manuale prima di finire nel gestionale
   - Razionale: zero-tolerance per errori di trascrizione/matching
   - Trade-off: operatore deve validare, ma evita costi di correzione in magazzino

3. **ADR-003: Multimodale Nativo**
   - Decisione: frontend accetta testo, immagini, audio, PDF — tutto in un'unica richiesta
   - Razionale: rispecchia come arrivano i clienti (mix di canali)
   - Trade-off: complessità di parsing, ma scalabile

4. **ADR-004: Storage Centralizzato per Allegati**
   - Decisione: object storage (S3-like) per audio/immagini, PostgreSQL per metadati
   - Razionale: separa hot-path (metadata queries) da cold (file storage)
   - Trade-off: due sistemi vs. uno, ma performance migliora

---

## Requisiti Funzionali

### RF-001: Ricezione Richiesta WhatsApp
**Descrizione**: Il sistema riceve messaggi WhatsApp via webhook, estrae testo, immagini e audio allegati.  
**Criteri di Accettazione**:
- Webhook parser valida firma WhatsApp Business API
- Testo estratto e memorizzato
- Immagini / audio scaricati e archiviati
- Richiesta creata nello stato `DRAFT`

### RF-002: Ricezione Richiesta Email
**Descrizione**: Il sistema si connette a mailbox aziendali (IMAP) e scarica email con allegati.  
**Criteri di Accettazione**:
- Parser supporta IMAP standard
- Allega testo email + body + allegati
- Marca email come processata (flag interno)
- Richiesta creata nello stato `DRAFT`

### RF-003: Upload Manuale
**Descrizione**: Alberto carica manualmente richieste (testo libero, immagine, audio, PDF).  
**Criteri di Accettazione**:
- Web UI form accept drag-and-drop
- File size max 50 MB
- Supporta .txt, .pdf, .jpg, .png, .wav, .mp3
- Richiesta creata nello stato `DRAFT`

### RF-004: Trascrizione Audio
**Descrizione**: Il sistema trascrizione file audio (MP3, WAV, WebM) usando Whisper API.  
**Criteri di Accettazione**:
- Supporta lingue: italiano, inglese
- Ritorno in max 30 sec per audio < 5 min
- Salva testo trascritto in DB
- Contrassegna task come completato

### RF-005: OCR Immagini
**Descrizione**: Il sistema estrae testo da immagini (screenshot liste cliente, foto cataloghi).  
**Criteri di Accettazione**:
- Supporta JPG, PNG, TIFF
- Estrae testo gerarchico (righe, colonne)
- Ritorno in max 10 sec per immagine < 5MB
- Salva testo in DB

### RF-006: Estrazione Righe Prodotto Strutturate
**Descrizione**: Dato il testo libero (trascritto, OCR, originale), estrae righe di ordine strutturate.  
**Criteri di Accettazione**:
- Estrae per ogni riga: descrizione prodotto, quantità, U.M., note cliente, urgenza
- Ritorna JSON con array di `{description, quantity, unit_of_measure, notes, urgent}`
- Confidence score per ogni campo
- Gestisce assenza di campi (es. no U.M.) con default ragionevole

### RF-007: Matching Catalogo con 3 Suggerimenti
**Descrizione**: Dato il catalogo (Excel/export), per ogni descrizione prodotto, ritorna top-3 match dal catalogo.  
**Criteri di Accettazione**:
- Input: descrizione cliente, catalogo (schema: `{id, code, description, unit_of_measure}`)
- Output: array top-3 con `{code, description, unit_of_measure, confidence, reason}`
- Semantic search primario (embeddings), fuzzy match secondario
- Suggerisce alternative non solo match primo

### RF-008: Revisione Manuale - Interface Web
**Descrizione**: Alberto accede a UI web, visualizza bozza della richiesta, modifica righe, selezione matching, aggiunge note.  
**Criteri di Accettazione**:
- Visualizza: testo originale, righe estratte, top-3 match per riga
- Permette: edit testo libero, selezione match (click radial), aggiunta note
- Salva versione "reviewed" in DB
- Timestampa review (chi, quando)

### RF-009: Generazione Lista Prelievo PDF
**Descrizione**: Da richiesta validated, genera PDF stampabile per magazzino.  
**Criteri di Accettazione**:
- PDF include: data, cliente (se disponibile), righe (codice, descrizione, quantità, U.M., note, urgenza)
- Layout: tabella pulita, leggibile su carta A4
- Opzionale: QR code in alto a destra (link a richiesta nel sistema)
- Scaricabile da UI

### RF-010: Archivio Richieste
**Descrizione**: Tutte le richieste (draft, reviewed, final) sono archiviate con full history.  
**Criteri di Accettazione**:
- Ogni richiesta ha: ID unico, data creazione, cliente (se noto), allegati originali, versioni intermedie, versione final
- Search: per cliente, date range, stato, free-text
- Visualizza timeline: "Created" → "Reviewed" → "Sent to Warehouse" → "Picked"

### RF-011: Gestione Stati Richiesta
**Descrizione**: Ogni richiesta transita per stati: `DRAFT` → `REVIEWING` → `REVIEWED` → `SENT_TO_WAREHOUSE` → `PICKED` (o `CANCELLED`).  
**Criteri di Accettazione**:
- Transizioni sono esplicite e trackate
- Solo stato corretto permette azione (es. solo DRAFT può diventare REVIEWING)
- Audit log ogni transizione

### RF-012: Notifiche Interne
**Descrizione**: Quando richiesta è pronta per revisione, notifica Alberto. Quando pronta per magazzino, notifica Marco (later: via email/chat).  
**Criteri di Accettazione**:
- V1: UI notification (badge nel sistema)
- Opzione: email plain-text
- Non spam: una notifica per richiesta, non duplicate

### RF-013: Importazione Catalogo
**Descrizione**: Il sistema importa il file Excel catalogo e lo mantiene sincronizzato.  
**Criteri di Accettazione**:
- Upload file Excel (`code`, `description`, `unit_of_measure`, ... colonne custom)
- Valida schema
- Importa in DB, crea embeddings per semantic search
- Versione: timestamp + hash per traccia diff

### RF-014: API per Matching (Future Integration)
**Descrizione**: Espone endpoint REST per matching esterno (futura integrazione TeamSystem).  
**Criteri di Accettazione**:
- `POST /api/v1/match` accept `{description, catalog_version}` → ritorna top-3
- Autenticazione: API key interna
- Rate limit: 100 req/min per chiave

---

## Requisiti Non-Funzionali

### Sicurezza

- **Autenticazione**: JWT con 24h expiry; refresh token su server
- **Autorizzazione**: RBAC (Admin, Operator, Warehouse) — Operator vede review interface, Warehouse vede archive
- **Encryption**: TLS 1.3 per transit, encrypted fields a riposo per dati sensibili (cliente, email)
- **Validation**: input sanitization, SQL injection defense (ORM + parameterized queries)
- **Audit**: log tutte le azioni (review, matching, state change), immutable

### Integrazione

- **Catalogo**: import Excel, versioning, sincronizzazione giornaliera (later: live API)
- **TeamSystem**: Export CSV da bozza validated (V1), API REST (V2)
- **Email**: IMAP read-only (no send, avoid phishing)
- **WhatsApp**: Business API webhook receiver (no outbound, avoid unsolicited messages)

---

## Prossimi Step

1. **Backlog** — Esegui `/archetipo-spec` per trasformare questo PRD in una backlog di spec/user story
2. **Mockup UI** — Esegui `/archetipo-design` per creare mockup della interface di revisione
3. **Validazione** — Condividi il PRD con Alberto e Marco, verifica che rispecchi la realtà

---

_PRD generato via ARchetipo Product Inception — 25 Giugno 2026_  
_Sessione condotta da: Fabio Durso con il team ARchetipo_
