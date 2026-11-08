<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## StudyNest database setup and deployment

Set `DB_CONNECTION` and its matching database connection values in the server environment before migrating. Keep the production database persistent; switching to an empty database creates a new schema but does not copy data from the previous database.

Run these commands from `my_backend` after installing dependencies and configuring the production environment:

```powershell
php artisan config:clear
php artisan migrate --force
php artisan db:seed --class=ShopItemSeeder --force
php artisan config:cache
```

The shop catalog is seed data, not migration data. Run the idempotent `ShopItemSeeder` after migrations on every new database so the shop API has its catalog. Do not use `migrate:fresh` on a database containing data. Back up the database before changing its connection or applying production migrations.

Run `php artisan db:seed --class=DatabaseSeeder --force` to populate sections and the shop catalog. This seeder deliberately does not create demo users; create your own admin and teacher accounts through Tinker or the account-management flow.

The Flutter release must be built against the deployed HTTPS API URL:

```powershell
cd ..\Flutter
flutter build apk --release --dart-define=API_BASE_URL=https://api.example.com/api
```

Replace the example URL with the Laravel API's public URL. The configured URL is embedded into that build; create a new build if it changes.

## Firebase Storage for uploaded files

Laravel can store lessons and activity attachments in a private Firebase Storage bucket instead of the Railway container filesystem. Firebase Storage requires the Firebase project to use the Blaze billing plan; charges depend on storage, downloads, and operations.

1. Enable Cloud Storage in the Firebase project and create a bucket. Keep the bucket private and enable uniform bucket-level access.
2. Create a service account with Storage Object Admin access to that bucket. Keep its JSON key private.
3. Base64-encode the service-account JSON locally (PowerShell: `[Convert]::ToBase64String([IO.File]::ReadAllBytes("service-account.json"))`), then set these Railway backend variables:
   - `PUBLIC_STORAGE_DRIVER=gcs`
   - `FIREBASE_STORAGE_PROJECT_ID` to the Firebase/Google Cloud project ID
   - `FIREBASE_STORAGE_BUCKET` to the exact bucket name
   - `FIREBASE_STORAGE_CREDENTIALS_BASE64` to the base64-encoded JSON
   - `FIREBASE_STORAGE_SIGNED_URL_MINUTES=60` (optional; controls how long returned file links work)
4. Redeploy the backend. Laravel will generate short-lived signed links for file URLs; never put the service-account key in the frontend, repository, or a chat message.

Leave `PUBLIC_STORAGE_DRIVER=local` for local development unless Firebase credentials and a bucket are configured. Switching storage does not migrate existing uploads: copy any existing files to the bucket before changing the production setting. Browser-based clients may also need the Firebase bucket's CORS policy configured for their deployed origins.

## Assignment attachment uploads

Teacher assignments support photos (up to 5 MB), PDF/PowerPoint files (up to 100 MB), and short videos (up to 50 MB and five minutes). On the production PHP runtime, set `upload_max_filesize` to at least `100M` and `post_max_size` to at least `110M`; configure the web server or hosting proxy request-body limit to at least `110 MB` as well. Keep these limits aligned with the validation in `MediaController`. Attachments use Laravel's `public` storage disk, so configure persistent or cloud-backed storage before deploying where local container filesystems are ephemeral.
