# TradeScope 2.1 - Sistema di analisi di mercato con IA (Claude o GPT)

Applicazione PHP autonoma, progettata per hosting condiviso Hostinger (Premium/Business).
Analizza in modo continuo crypto, azioni, indici e forex combinando analisi tecnica calcolata
dal server e interpretazione dell'intelligenza artificiale, e disegna sul grafico la proiezione del
possibile andamento futuro con banda di confidenza, livelli operativi e scenari probabilistici.

**Novita della 2.1:** sono supportati due fornitori di IA intercambiabili, **Anthropic (Claude)** e
**OpenAI (GPT)**. Puoi configurare una sola chiave o entrambe: il fornitore usato dalle analisi
automatiche si sceglie nelle Impostazioni, mentre dalla dashboard puoi lanciare un'analisi manuale
con qualsiasi modello di cui hai inserito la chiave.

---

## 0. Novita della versione 2.0

La 2.0 e' un aggiornamento del **motore di analisi**, pensato per alzare l'affidabilita
delle indicazioni, non per aggiungere funzioni cosmetiche.

| Novita | Cosa cambia in pratica |
|---|---|
| **Punteggio di confluenza** (`inc/indicators2.php`) | Il server calcola un punteggio deterministico da -100 a +100 sommando ~20 contributi indipendenti (trend HTF, struttura, medie, ADX/DI, momentum, volumi, VWAP/POC, divergenze, SMC, derivati, consenso MTF), con moltiplicatore che penalizza i segnali fuori regime. Il punteggio **vincola la confidenza** dichiarabile da Claude: non puo' piu' sovrastimare un setto debole |
| **Consenso multi-timeframe reale** | Quattro timeframe (timing, operativo, superiore, macro) vengono letti e votati separatamente; il consenso e il numero di timeframe allineati entrano nel prompt e nella dashboard |
| **Smart Money Concepts** | BOS/CHoCH, order block con stato mitigato/non mitigato, fair value gap ancora aperti, prese di liquidita (sweep), massimi/minimi equali: calcolati dal server, non "immaginati" dal modello |
| **Regime di mercato** | Classificazione trend / range / compressione / espansione con la strategia coerente. In compressione o espansione i segnali direzionali vengono automaticamente depotenziati |
| **Lettura Wyckoff** | Fase (accumulo, distribuzione, markup, markdown) con rilevamento di spring e upthrust |
| **Nodi di volume** | HVN, LVN e naked POC (POC di finestre passate mai piu toccati: obiettivi naturali del prezzo) |
| **Livelli ancorati** | Open e range di giorno / settimana / mese precedenti, VWAP settimanale, sessione della candela |
| **Divergenze automatiche** | Divergenze regolari e nascoste su RSI e istogramma MACD sugli ultimi swing |
| **Derivati crypto** (`inc/derivatives.php`) | Funding rate (e annualizzato), open interest e sua variazione, long/short ratio retail e big trader, taker buy/sell, base perpetual-spot, Fear & Greed, dominance BTC, correlazione con BTC. Con lettura operativa (long affollati, rischio squeeze, movimento da liquidazioni) |
| **Proiezione statistica empirica** | La banda di confidenza non e' piu ATR*sqrt(t) ma la **distribuzione reale** dei rendimenti a N barre di quello strumento (quantili 10/25/50/75/90). Claude la deforma secondo lo scenario, senza poter inventare ampiezze |
| **Auto-calibrazione sullo storico** | Il prompt include gli esiti verificati delle analisi precedenti su quello strumento e il win rate per fascia di confidenza: se le analisi ad alta confidenza hanno fallito, il modello abbassa la confidenza |
| **Validazione severa del piano** | Stop dentro il rumore allargato a 0,8 ATR, stop incoerenti ricalcolati, R/R ricalcolato dal server, valore atteso in R, giudizio finale di operativita (eseguibile / size ridotta / attendere) |
| **Export Pine Script** (`inc/pine.php`) | Un click genera un indicatore Pine v5 che disegna dentro TradingView percorso proiettato, banda, ingresso, stop, target, livelli e zone: da la' si lavorano con tutti gli strumenti di TradingView |
| **Nuova dashboard** (`pro.php`) | Punteggio di confluenza con contributi dettagliati, consenso MTF, regime, derivati, zone SMC, piano operativo, scenari, valore atteso. La dashboard 1.0 (`index.php`) resta disponibile |

### Aggiornare una installazione 1.0 esistente
1. Caricare i file nuovi: `inc/indicators2.php`, `inc/derivatives.php`, `inc/analysis2.php`,
   `inc/pine.php`, `pro.php`, `assets/pro.css`, `assets/pro.js`.
2. Sovrascrivere: `inc/bootstrap.php`, `inc/analysis.php`, `api.php`, `index.php`, `LEGGIMI.md`.
3. Non serve toccare il database: le colonne nuove (`score`, `quality`, `regime`, `ev`, `engine`)
   vengono aggiunte automaticamente alla prima analisi.
4. In `inc/config.php` (facoltativo) portare `claude.max_tokens` a 6000 e `engine.candles` a 400.
   Per tornare al motore 1.0: `engine.use_v2 = 0`.
5. Aprire `pro.php` e premere **Analizza ora**.

Costo per analisi in 2.0: circa 12.000-18.000 token di input (snapshot piu ricco) e
2.000-3.500 di output. Su Sonnet indicativamente 0,06-0,09 $ per analisi.

---

## 1. Cosa contiene il sistema

| Componente | Descrizione |
|---|---|
| `index.php` | Dashboard: watchlist, KPI, grafico proiezione, grafico live TradingView, analisi completa |
| `history.php` | Storico di tutte le analisi con verifica automatica degli esiti (target / stop / scaduta) |
| `settings.php` | Fornitore IA, chiavi API (Anthropic e OpenAI), modelli, parametri del motore, cron, cambio password, diagnostica |
| `cron.php` | Analisi automatiche periodiche (e' cio' che tiene il sistema sempre aggiornato) |
| `api.php` | API JSON interna usata dalla dashboard |
| `install.php` | Installazione guidata (da eliminare dopo l'installazione) |
| `inc/` | Motore: database, autenticazione, provider dati, indicatori, prompt e client IA (`ai.php`: Anthropic + OpenAI) |
| `assets/` | Interfaccia e libreria grafica TradingView Lightweight Charts (inclusa, nessun download) |
| `storage/` | Database SQLite, cache e log (cartella protetta) |

### Analisi tecnica calcolata dal server (nessuna dipendenza esterna)
EMA 9/21/50/200, RSI 14, MACD 12/26/9, ATR 14 e percentile di volatilita', Bande di Bollinger,
Stocastico, ADX/+DI/-DI, OBV, VWAP 50, volume relativo, swing point con clustering di supporti e
resistenze, struttura di mercato (HH/HL - LH/LL), Fibonacci (ritracciamenti ed estensioni),
volume profile con POC e value area, pattern candlestick, contesto sul timeframe superiore.

### Cosa produce l'IA
Bias e confidenza, sintesi e struttura di mercato, fattori chiave, lettura di trend/momentum/
volatilita'/volumi, livelli operativi, tre scenari con probabilita', percorso di prezzo proiettato
con banda di confidenza, piano operativo completo (ingresso, zona, stop, due target, rischio/
rendimento, trigger, invalidazione) e punti da monitorare.

---

## 2. Installazione su Hostinger (10 minuti)

### Passo 1 - Caricare i file
1. hPanel -> **File Manager** (oppure FTP).
2. Entrare in `public_html`.
3. Creare la cartella `tradescope` (oppure caricare tutto nella radice se il dominio e' dedicato).
4. Caricare `tradescope.zip` dentro la cartella ed estrarlo con **Estrai** del File Manager.

### Passo 2 - Impostare PHP 8.1 o superiore
hPanel -> **Avanzate -> Configurazione PHP**: selezionare PHP 8.1/8.2/8.3.
Nella scheda **Estensioni** verificare che siano attive: `pdo_sqlite`, `curl`, `mbstring`, `json`.

### Passo 3 - Eseguire l'installazione guidata
Aprire nel browser:

    https://iltuodominio.it/tradescope/install.php

Inserire nome utente, password (minimo 10 caratteri), tipo di database (SQLite consigliato,
non richiede alcuna configurazione) e, se gia' disponibile, la chiave API Anthropic.

### Passo 4 - Eliminare install.php
Dal File Manager, cancellare `install.php`. **Passaggio obbligatorio.**

### Passo 5 - Chiave API (Anthropic e/o OpenAI)

**Anthropic (Claude)**
1. Creare la chiave su <https://console.anthropic.com/settings/keys>
2. Caricare credito nella console (l'uso e' a consumo).
3. Incollarla in **Impostazioni -> Chiave API Anthropic** e premere **Verifica chiave Anthropic**.

**OpenAI (GPT)**
1. Creare la chiave su <https://platform.openai.com/api-keys>
2. Caricare credito in **Billing** (uso a consumo).
3. Incollarla in **Impostazioni -> Chiave API OpenAI** e premere **Verifica chiave OpenAI**.

In **Impostazioni -> Fornitore usato per le analisi automatiche** si sceglie quale dei due usa il cron.
Se una chiave non e' presente, i relativi modelli restano disattivati nel menu della dashboard.

### Passo 6 - Attivare le analisi automatiche (cron)
hPanel -> **Avanzate -> Cron Job** -> Crea nuovo cron job.

- Tipo: comando personalizzato
- Frequenza consigliata: ogni 15 minuti oppure ogni ora
- Comando:

      /usr/bin/php /home/UTENTE/domains/TUODOMINIO/public_html/tradescope/cron.php

  Il percorso esatto e' mostrato nella pagina **Impostazioni** del sistema.
  In alternativa, via URL:

      wget -q -O /dev/null "https://iltuodominio.it/tradescope/cron.php?token=TOKEN"

Il cron analizza a rotazione gli strumenti della watchlist (di default 3 per esecuzione,
mai piu' di una volta ogni 30 minuti per lo stesso strumento), aggiorna gli esiti delle analisi
passate e ripulisce cache e storico. E' questo che rende il sistema **sempre consultabile**:
anche aprendo il sito dopo giorni, le analisi sono gia' pronte e aggiornate.

---

## 3. Uso quotidiano

- **Watchlist**: aggiungere strumenti indicando simbolo, fonte dati e timeframe.
  - Crypto (Binance/Bybit): `BTCUSDT`, `ETHUSDT`, `SOLUSDT`, `XRPUSDT`
  - Azioni e indici (Yahoo): `AAPL`, `TSLA`, `^GSPC`, `ENI.MI`, `ISP.MI`
  - Forex e materie prime (Yahoo): `EURUSD=X`, `GC=F` (oro), `CL=F` (petrolio)
- **Analizza ora**: genera immediatamente una nuova analisi con il modello selezionato in alto
  (il menu raggruppa i modelli Claude e GPT; il fornitore viene riconosciuto dal modello scelto).
- **Proiezione IA**: grafico con candele, EMA, percorso proiettato (linea tratteggiata azzurra),
  banda di confidenza (~80%), linee di ingresso, stop e target.
- **Grafico live TradingView**: grafico completo con tutti gli strumenti di disegno di TradingView.
- **Storico**: ogni analisi viene verificata automaticamente sui prezzi reali successivi
  (target raggiunto, stop colpito, scaduta) con MFE/MAE e win rate complessivo.

---

## 4. Costi indicativi API

Ogni analisi consuma all'incirca 6.000-9.000 token di input e 1.500-3.000 di output.

| Fornitore | Modello | Costo indicativo per analisi |
|---|---|---|
| Anthropic | `claude-haiku-4-5` | ~0,01-0,02 $ |
| Anthropic | `claude-sonnet-5` | ~0,03-0,05 $ |
| Anthropic | `claude-opus-5` | ~0,08-0,12 $ |
| Anthropic | `claude-fable-5-1` | ~0,15-0,25 $ |
| OpenAI | `gpt-5.6-luna` | ~0,002-0,004 $ |
| OpenAI | `gpt-5.6-terra` | ~0,01-0,02 $ |
| OpenAI | `gpt-5.6-sol` | ~0,03-0,05 $ |
| OpenAI | `gpt-6-astra` | ~0,08-0,12 $ |

Con 3 strumenti analizzati ogni ora su Sonnet 5 o GPT-5.6 Sol: circa 3-4 $ al giorno.
Per contenere i costi: aumentare **Minuti minimi tra due analisi**, ridurre
**Strumenti per esecuzione** o impostare il cron ogni 4 ore.

---

## 5. Sicurezza

- Accesso protetto da login con hash della password (bcrypt), token CSRF su ogni azione,
  blocco dopo 8 tentativi falliti in 15 minuti, sessione con scadenza.
- Le cartelle `storage/` e `inc/` sono bloccate via `.htaccess` (database, chiavi API e log
  non sono raggiungibili dal web).
- HTTPS forzato, header di sicurezza attivi, `noindex` su tutte le pagine.
- Se il sito e' su un dominio pubblico, si consiglia di installare il sistema in una
  sottocartella non indicizzata (es. `/analisi-privata/`).

---

## 6. Risoluzione problemi

| Sintomo | Causa e soluzione |
|---|---|
| "Binance: HTTP 451" o timeout | L'IP del server e' bloccato dall'exchange: il sistema passa automaticamente a Bybit; se persiste, usare Yahoo per quel simbolo |
| "Chiave API ... non configurata" | Inserire la chiave del fornitore selezionato in Impostazioni e premere Verifica |
| Errore OpenAI su un parametro non supportato | Gestito automaticamente: la chiamata viene ripetuta senza il parametro rifiutato |
| Il cron non parte | Controllare il percorso PHP: alcuni server usano `/usr/bin/php8.2`; in alternativa usare la modalita' URL con token |
| Pagina bianca | Attivare la visualizzazione errori in hPanel e controllare `storage/logs/app-AAAA-MM.log` |
| "Cartella storage non scrivibile" | Impostare i permessi 755 su `storage/` dal File Manager |
| Grafico TradingView vuoto | Il simbolo TradingView non esiste: indicarlo a mano nel campo apposito (es. `NASDAQ:AAPL`) |

---

## 7. Struttura del database

`ts_watchlist` (strumenti monitorati), `ts_analyses` (ogni analisi con payload completo, snapshot
tecnico e esito verificato), `ts_runs` (esecuzioni del cron), `ts_settings` (impostazioni),
`ts_login_attempts` (protezione accessi).

Backup: e' sufficiente scaricare `storage/tradescope.sqlite` (o esportare il database MySQL).
