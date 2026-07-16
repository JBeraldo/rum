# Rum

Rum is a shared household media library. It brings movies and series from Radarr and Sonarr into one interface, lets members maintain a shared wishlist, and can track active downloads through qBittorrent.

## Features

- Browse and search a combined movie and series library.
- Connect Radarr and Sonarr, including a default quality profile for requests.
- Add titles to a shared wishlist and process requests in FIFO order.
- Preserve a 20 GB free-space reserve before requesting a title.
- Track active qBittorrent downloads and their progress.
- Manage member and administrator roles.

## Requirements

- Docker Desktop
- Composer and Node.js (for the initial dependency installation)

The application uses Laravel Sail, PHP 8.5, PostgreSQL, Valkey, Livewire, and Flux UI.

## Local setup

1. Install PHP dependencies and create the environment file:

   ```sh
   composer install
   cp .env.example .env
   ```

2. Configure `.env` for the PostgreSQL service in `compose.yaml`:

   ```dotenv
   APP_URL=http://localhost
   DB_CONNECTION=pgsql
   DB_HOST=pgsql
   DB_PORT=5432
   DB_DATABASE=laravel
   DB_USERNAME=sail
   DB_PASSWORD=password
   ```

3. Start the application services, install frontend dependencies, and prepare the database:

   ```sh
   vendor/bin/sail up -d
   vendor/bin/sail composer install
   vendor/bin/sail npm install
   vendor/bin/sail artisan key:generate
   vendor/bin/sail artisan migrate --seed
   vendor/bin/sail npm run build
   ```

4. Open [http://localhost](http://localhost), register an account, then grant it administrator access:

   ```sh
   vendor/bin/sail artisan users:grant-role you@example.com admin
   ```

Administrators can configure integrations and manage user roles from **Settings**.

## Integrations

Configure integrations in **Settings → Integrations**. Rum validates each connection before saving it.

| Service | Purpose | Authentication |
| --- | --- | --- |
| Radarr | Sync movie catalog and request movies | URL and API key |
| Sonarr | Sync series catalog and request series | URL and API key |
| qBittorrent | Track transfers associated with library and wishlist items | URL plus an API key, or username and password |

For Radarr and Sonarr requests, select a default quality profile after saving the connection. Rum uses the connected service's configured root folders and only submits pending wishlist items when the selected folder retains at least 20 GB free.

## Background work

The scheduler runs the following commands:

| Command | Schedule | Purpose |
| --- | --- | --- |
| `library:sync` | Hourly | Sync Radarr and Sonarr catalogs |
| `wishlist:process` | Hourly | Submit eligible pending wishlist requests |
| `downloads:sync` | Every minute | Sync qBittorrent transfers |

For local development, run the scheduler in a separate terminal:

```sh
vendor/bin/sail artisan schedule:work
```

Each command may also be run manually with `vendor/bin/sail artisan <command>`.

## Development

Start Vite's development server when changing frontend assets:

```sh
vendor/bin/sail npm run dev
```

Format changed PHP files:

```sh
vendor/bin/sail bin pint --dirty --format agent
```

Run the test suite:

```sh
vendor/bin/sail artisan test --compact
```

## License

This project is licensed under the MIT License.
