# ai-ordini-idraulica

Catalogo prodotti idraulica con ricerca ibrida (full-text + vettoriale).
Stack: **Laravel 13** + **PostgreSQL 16/pgvector** + **Redis**, orchestrato con **Laravel Sail** (Docker).

## Requisiti

- Docker + Docker Compose
- Nessuna installazione manuale di PHP, PostgreSQL o Redis: gira tutto nei container Sail.

## Avvio rapido

```bash
# 1. Copia le variabili d'ambiente (già preconfigurate per pgsql + redis)
cp .env.example .env

# 2. Installa le dipendenze PHP (una tantum, via container)
docker run --rm -v "$(pwd)":/var/www/html -w /var/www/html \
    laravelsail/php84-composer:latest composer install

# 3. Avvia l'ambiente (laravel.test, pgsql con pgvector, redis)
./vendor/bin/sail up -d

# 4. Genera la app key ed esegui le migration (abilita l'estensione pgvector)
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
```

L'applicazione è disponibile su http://localhost.

## Variabili d'ambiente principali

Preconfigurate in `.env.example` per l'ambiente Sail:

| Variabile          | Valore  | Note                     |
| ------------------ | ------- | ------------------------ |
| `DB_CONNECTION`    | `pgsql` | PostgreSQL 16 + pgvector |
| `DB_HOST`          | `pgsql` | nome del servizio Docker |
| `DB_PORT`          | `5432`  |                          |
| `QUEUE_CONNECTION` | `redis` | code su Redis            |
| `CACHE_STORE`      | `redis` | cache su Redis           |
| `REDIS_HOST`       | `redis` | nome del servizio Docker |

## Checklist di verifica ambiente (smoke test manuale)

Dopo `sail up`, verifica i tre criteri di accettazione:

```bash
# 1. Tutti i container sono attivi (laravel.test, pgsql, redis)
./vendor/bin/sail ps

# 2. L'estensione pgvector è attiva (deve restituire una riga)
./vendor/bin/sail psql -c "SELECT 1 FROM pg_extension WHERE extname='vector';"

# 3. Redis risponde al PING (deve restituire PONG)
./vendor/bin/sail redis-cli ping
```

## Test automatici

```bash
# Suite completa (unit + feature). I test di connettività girano
# contro i servizi Sail live; fuori dai container vengono saltati.
./vendor/bin/sail test
```

I test coprono i criteri di accettazione di US-001:

- `DatabaseConnectivityTest` — connessione a PostgreSQL ed estensione `vector` attiva.
- `RedisConnectivityTest` — Redis raggiungibile e rispondente al PING.
- `EnvironmentConfigurationTest` — variabili `pgsql`/`redis` configurate in `.env.example`.
