# Handoff: normalizzazione attributi AI-only e ricerca a prodotto singolo

Documento di handoff per una nuova sessione da passare ad archetipo (spec →
plan → implement). Raccoglie le decisioni prese in una sessione di analisi
sulla struttura attuale di attributi/ricerca prodotto e su cosa va cambiato.
Non è ancora uno spec: sono decisioni di direzione, da tradurre in una o più
user story (probabilmente un nuovo epic, es. "Normalizzazione AI e Ricerca a
Prodotto Singolo").

## Contesto: perché si è aperta questa analisi

L'obiettivo finale del prodotto è: l'utente scrive in linguaggio naturale cosa
cerca (es. "tubo inox 1 pollice", "condizionatore 7kw"), il sistema deve
restituire **il singolo prodotto più vicino alla richiesta**, con uno score.
Se il primo risultato ha uno score alto e ben distaccato dal secondo, si
considera trovato; altrimenti l'utente deve affinare la ricerca o scegliere
manualmente tra i candidati.

Analizzando la struttura dati attuale per capire se regge questo obiettivo,
sono emerse inconsistenze che richiedono modifiche prima di costruire il
motore di ricerca NL.

## Stato attuale (as-is)

- **`product_attributes`** (EAV): `product_id`, `key` (stringa libera
  snake_case), `value_num`, `value_text`, `unit`, `source`
  (`regex`|`ai`|`manual`|`file`), `confidence`. Una riga per attributo per
  prodotto.
- **Estrazione regex** ([app/Services/Enrichment/AttributeResolver.php](../app/Services/Enrichment/AttributeResolver.php)):
  10 estrattori deterministici (potenza_kw, capacita_litri, attacco_pollici,
  diametro_nominale, pressione_nominale, pressione_bar, tensione_volt,
  potenza_watt, colore_ral, materiale). `source = 'regex'`, confidence fissa
  a 100.
- **Estrazione AI** ([app/Services/Ai/ClassificationPromptBuilder.php](../app/Services/Ai/ClassificationPromptBuilder.php),
  [app/Services/Enrichment/EnrichmentApplier.php](../app/Services/Enrichment/EnrichmentApplier.php)):
  l'AI valida/corregge gli attributi noti e propone chiavi libere aggiuntive,
  ciascuna con propria confidence. Scritte con `source = 'ai'`, mai
  sovrascrivendo un valore già `manual`/`file`.
- **Bug di frammentazione già presente**: `potenza_kw` e `potenza_watt` sono
  due chiavi diverse per la stessa grandezza fisica, a seconda di quale unità
  compariva nel testo sorgente. Un filtro su una chiave non trova mai i
  prodotti finiti sotto l'altra. Stesso rischio esiste per litri/millilitri
  (oggi non c'è nemmeno un estrattore per ML) e per qualunque nuova chiave
  "quasi sinonimo" che l'AI inventi in futuro (es. `potenza` vs
  `potenza_nominale`).
- **Nessuno strato di normalizzazione unità di misura** esiste oggi, tranne
  un caso singolo hardcoded (`extractPressioneBar()` converte MBAR→bar prima
  di salvare). Per tutto il resto, l'unità salvata è quella incontrata nel
  testo, non un'unità canonica.
- **`product_type` e `enriched_description` esistono già nella pipeline AI**
  ([app/Services/Ai/ClassifiedProduct.php](../app/Services/Ai/ClassifiedProduct.php),
  [app/Services/Ai/ClassificationResponseValidator.php](../app/Services/Ai/ClassificationResponseValidator.php))
  ma sono **campi "morti"**: vengono richiesti all'AI e loggati dentro
  `EnrichmentLog` ([app/Jobs/ClassifyProductsBatchJob.php:595-604](../app/Jobs/ClassifyProductsBatchJob.php#L595-L604)),
  ma non esiste nessuna colonna su `products` che li persista, e nessun
  codice li consuma.
- **`ProductBase`** ([app/Models/ProductBase.php](../app/Models/ProductBase.php),
  [app/Services/Enrichment/GroupingResolver.php](../app/Services/Enrichment/GroupingResolver.php)):
  raggruppa varianti dello stesso articolo commerciale (es. stessa caldaia in
  tagli di potenza diversi) sotto un'unica riga, tramite una regex che
  strippa il token di taglia da un pattern di codice serie specifico (`VAI
  8-025` → `VAI 8`). L'embedding vive per-gruppo
  (`product_embeddings.product_base_id`), non per singolo prodotto.
  `SearchService` ([app/Services/Search/SearchService.php](../app/Services/Search/SearchService.php))
  aggrega `variants_count` e `power_range_min/max` per gruppo.

## Decisioni prese in questa sessione (to-be)

### 1. Eliminare l'estrazione via regex, normalizzazione solo AI

`AttributeResolver` va rimosso. Troppa casistica da gestire con regex (e
comunque fragile, come dimostra il caso litri/millilitri). Tutta
l'estrazione/normalizzazione attributi passa dalla classificazione AI
esistente.

### 2. Registro `attribute_definitions`

Nuova tabella con, per ciascuna chiave attributo nota: `key`, `data_type`
(numerico|testuale), `canonical_unit`, `description`, eventuali unità/sinonimi
accettati in input per la conversione. Sostituisce/arricchisce il contesto
oggi passato all'AI in `ClassificationPromptBuilder::knownAttributesText()`
(che oggi passa solo gli attributi già noti *di quel prodotto*, non un
vocabolario globale delle chiavi ammesse).

L'AI deve:
- usare le chiavi/unità canoniche del registro quando applicabile;
- **convertire sempre il valore estratto nell'unità canonica** prima di
  restituirlo (risolve alla radice il caso kw/watt e litri/millilitri);
- poter proporre nuove chiavi non ancora nel registro, nella stessa
  struttura (key, data_type, unit, description).

Le nuove chiavi proposte dall'AI **non diventano canoniche automaticamente**:
vanno instradate nella coda di revisione già esistente
(`enrichment_proposals` / Review Queue, US-039/040/041) con un controllo
(anche solo umano in prima battuta) che verifichi che non esista già una
chiave semanticamente equivalente — altrimenti si ricrea lo stesso problema
di frammentazione un livello più sopra.

### 3. Persistere e restringere `product_type`

Riusare il campo `product_type` già presente nel contratto AI (non crearne
uno nuovo — si sovrapporrebbe a `enriched_description`, che invece resta una
descrizione più ricca, non necessariamente ripulita). Serve:
- aggiungere la colonna su `products` (oggi non esiste, vedi sopra);
- stringere l'istruzione nel prompt: `product_type` deve contenere **solo il
  nome/tipo del prodotto**, esplicitamente **senza** marca, famiglia,
  sottofamiglia, né valori di attributi (es. "Caldaia a condensazione", non
  "Vaillant caldaia condensazione 25kW").

### 4. `product_type` alimenta l'embedding di ricerca

Lo scopo primario di `product_type` è migliorare l'embedding usato dal motore
di ricerca. Questo richiede di rivedere
`ProductBase::composeDescriptionAi()` (oggi concatena title + brand + family
+ subfamily) per usare `product_type` pulito invece del `title` attuale
(quest'ultimo generato da `GroupingResolver` e ancora "sporco" — contiene
marca e token della descrizione grezza).

### 5. `ProductBase` diventa superfluo — ricerca a prodotto singolo (flat)

Obiettivo finale confermato: trovare *il* prodotto, non un gruppo di
varianti. Con `product_type` pulito, l'embedding può essere calcolato
direttamente per `Product` (per singolo SKU) su `product_type + brand`,
rendendo gli embedding delle varianti di uno stesso modello naturalmente
vicini tra loro nel ranking, senza bisogno di un raggruppamento
pre-calcolato. Di conseguenza diventano superflui/da rimuovere:
- `ProductBase` (tabella + modello);
- `GroupingResolver` (job/regex di raggruppamento);
- `product_embeddings.product_base_id` → l'embedding va agganciato a
  `product_id`;
- la logica di `variants_count`/`power_range` in `SearchService` (nessuna
  aggregazione per gruppo, risultati sempre a livello di singolo prodotto).

**Punto di attenzione per chi pianifica**: verificare se serve comunque una
qualche forma di deduplica/collasso in UI (es. varianti quasi identiche che
finiscono entrambe in cima alla lista) — la decisione presa è che va bene una
lista piatta con ranking + filtri a restringere, ma vale la pena confermarlo
in fase di spec.

### 6. Funnel di ricerca NL → prodotto singolo

Pipeline decisa per la ricerca, da costruire come componente nuovo (oggi non
esiste nessun parser di query in linguaggio naturale):

1. **Parser AI della query**: scompone il testo libero in (a) parte
   descrittiva/tipo prodotto e (b) attributi espliciti con valore, usando il
   registro `attribute_definitions` come vocabolario chiuso per il grounding
   e per la conversione in unità canonica (stesso principio del punto 2, ma
   in lettura invece che in scrittura).
2. **Filtro rigido sugli attributi estratti**: gli attributi con valore
   esplicito nella query (es. "1 pollice") vanno applicati come filtro duro
   *prima* dello scoring semantico — non vanno lasciati dentro un punteggio
   sfumato insieme al testo, altrimenti un prodotto con attributo diverso ma
   testo simile (es. tubo da 3/4" quando si cercava 1") può comunque
   posizionarsi in alto. `SearchService::applyFilters()` con
   `filters['attributes']` fa già esattamente questo — va solo generalizzato
   a livello di singolo `Product` invece che di `ProductBase` (vedi punto 5).
3. **Ranking semantico/FTS solo sui sopravvissuti al filtro** (fusione
   vettoriale + full-text, meccanismo già esistente in
   `SearchService::applyRanking()`).
4. **Margine di confidenza top1 vs top2** per decidere match automatico vs
   richiesta di conferma/disambiguazione manuale. **Da rivedere**: il
   `combined_score` attuale (somma pesata di `ts_rank` + coseno) non è su una
   scala stabile/comparabile tra query diverse (`ts_rank` varia con
   lunghezza/rarità dei termini), quindi non è affidabile per calcolare un
   "distacco" assoluto. Serve normalizzare (es. usare solo la componente
   coseno, o un margine relativo `(score1 - score2) / score1` calcolato sul
   set di candidati della singola query) prima di poter fissare soglie di
   auto-match.

## Domande aperte da chiudere in fase di planning

- Schema esatto di `attribute_definitions` (colonne, come si collega a
  `product_attributes.key`, migrazione/backfill delle chiavi già esistenti
  tipo `potenza_kw`/`potenza_watt` verso un'unica chiave canonica).
- Le righe `product_attributes` già scritte con `source = 'regex'` vanno
  rigenerate via AI, lasciate come sono, o marcate per re-review?
  `EnrichmentApplier::isBlockedByAuthoritativeSource()` oggi tratta `regex` e
  `ai` come intercambiabili (l'AI può sovrascrivere `regex`) — verificare che
  questa logica resti coerente una volta rimossa l'estrazione regex.
  Sempre da valutare: la coda di revisione già esistente (US-039/040/041)
  potrebbe entrare in gioco anche per la migrazione delle chiavi esistenti.
- Formula e soglie esatte per il margine di confidenza top1/top2
  (auto-resolve vs disambiguazione).
- Se e come va gestita una eventuale deduplica in UI in assenza di
  `ProductBase` (vedi punto 5).
- Il flusso di revisione per le nuove chiavi proposte dall'AI in
  `attribute_definitions`: estendere `enrichment_proposals`/Review Queue
  esistente o serve un tipo di proposta/tabella dedicata?

## File/componenti esistenti impattati (riferimento per il planning)

- `app/Services/Enrichment/AttributeResolver.php` — da rimuovere.
- `app/Services/Enrichment/GroupingResolver.php` — da rimuovere (punto 5).
- `app/Models/ProductBase.php`, `app/Models/ProductEmbedding.php` — da
  rivedere/rimuovere (punto 5).
- `app/Services/Ai/ClassificationPromptBuilder.php` — da estendere per
  passare il registro `attribute_definitions` come contesto e stringere le
  istruzioni su `product_type`.
- `app/Services/Ai/ClassifiedProduct.php`,
  `app/Services/Ai/ClassificationResponseValidator.php` — invariati nella
  forma, ma `product_type` deve iniziare a essere effettivamente persistito.
- `app/Services/Enrichment/EnrichmentApplier.php` — da estendere per scrivere
  `product_type` su `products` e per usare il registro nella conversione
  unità.
- `app/Jobs/ClassifyProductsBatchJob.php` — punto in cui oggi
  `product_type`/`enriched_description` vengono scartati dopo il logging;
  da collegare alla persistenza.
- `app/Services/Search/SearchService.php` — da adattare per lavorare su
  `Product` invece che su `ProductBase`, e per il nuovo funnel filtro-rigido
  → ranking → margine.
- `database/migrations/2026_07_01_161750_create_product_attributes_table.php`,
  `2026_07_03_195910_add_confidence_to_product_attributes_table.php` — schema
  di riferimento per `attribute_definitions`.
