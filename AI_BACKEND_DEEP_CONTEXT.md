# AI Backend Deep Context

## 1. Backend Identity and Purpose

This repository is a Laravel backend for an event management and ticketing platform API. The codebase is centered on event listings, ticket types, user authentication, user interactions, and personalized event recommendations.

The backend is responsible for:
- user authentication via email/password and Google OAuth,
- event creation and management,
- category and subcategory data,
- organizer and venue address data,
- ticket type management,
- FAQs for events,
- event options / metadata,
- likes / favorites,
- view tracking,
- purchase tracking data structure,
- personalized recommendations,
- role-based access control,
- frontend API support via Sanctum, CORS, and public image storage.

The code confirms the backend purpose as a ticket/event API. It includes actual implementation for user auth, event CRUD, category/subcategory listing, user likes/views, and a recommendations engine. It also includes scaffolded or incomplete purchase support.

## 2. Current Backend Status

### Stable / Working Areas
- Authentication routes exist for login, registration, logout, and `/api/user`.
- Google OAuth entry and callback exist in `app/Http/Controllers/Api/AuthController.php`.
- Event listing and retrieval exist in `app/Http/Controllers/Api/EventController.php`.
- Event creation, update, and delete are implemented in `EventController`.
- Category and subcategory list endpoints exist.
- Recommendation engine logic is implemented in `app/Services/RecommendationEngine.php` and exposed in `app/Http/Controllers/Api/RecommendationController.php`.
- Custom CORS middleware is in `app/Http/Middleware/Cors.php`.
- Session-based Sanctum integration is configured in `bootstrap/app.php` and `config/sanctum.php`.
- Seeders create categories, subcategories, and seeded events with ticket types and FAQs.

### Areas That Need Review
- `app/Models/User.php` does not implement `Spatie\Permission\Traits\HasRoles` or any role trait, but the controllers call `hasAnyRole()`.
- `users` table migration does not include a `role` column, yet `AuthController` returns `$user->role` in Google OAuth callback.
- `getLikedEvents` route in `routes/api.php` is not guarded by `auth:sanctum`, but the method assumes an authenticated user.
- `database/migrations/2025_11_27_050041_add_status_to_events_table.php` uses `DB::table()` without importing `Illuminate\Support\Facades\DB`.
- `database/factories/EventFactory.php` is empty.
- `app/Http/Controllers/Api/EventController.php` imports `Voku\Helper\AntiXSS` but does not use it.

### Areas Not Fully Implemented
- Payment processing routes/controllers are not found in current codebase.
- Purchase flow endpoints are absent; only the `Purchase` model and `purchases` table data structure exist.
- User roles and permissions are not fully wired because User model lacks Spatie role integration.
- There is no explicit event status management in the Event model, even though a `status` column is added via migration.
- The `EventSeeder` uses placeholder image content rather than valid image files.

### Areas Ready for Future Expansion
- Recommendations engine is implemented and cached.
- Ticket types and FAQs are present for events.
- Event options and pivot tables exist for metadata.
- Spatie permission tables are created, so RBAC can be completed.
- Google OAuth configuration is present in `config/services.php` and `AuthController`.
- Frontend integration support is present through `CORS`, `sanctum/csrf-cookie`, and `config/session.php`.

## 3. Tech Stack and Dependencies

### PHP / Laravel Stack

From `composer.json`:
- `php: ^8.2`
- `laravel/framework: ^12.0`
- `laravel/sanctum: ^4.1` - used for SPA/session-based authentication and API auth.
- `laravel/socialite: ^5.23` - used for Google OAuth in `app/Http/Controllers/Api/AuthController.php`.
- `laravel/tinker: ^2.10.1` - optional interactive console.
- `spatie/laravel-permission: ^6.17` - configured in `config/permission.php` and migrations, but traits are not used in the `User` model.
- `voku/anti-xss: ^4.1` - imported in `EventController` but not actually used in the current code.

### Frontend Build Tools Included in Backend Repo

From `package.json`:
- `vite` - frontend build tool.
- `laravel-vite-plugin` - integration plugin.
- `tailwindcss` - CSS utility framework.
- `@tailwindcss/vite` - Tailwind integration with Vite.
- `axios` - HTTP client for frontend API requests.
- `concurrently` - used in `composer.json` dev script to run server, queue, and Vite together.

No actual frontend source code is present beyond configuration and dependency references.

### Development Tools

From `composer.json` dev dependencies:
- `fakerphp/faker` - used by model factories.
- `laravel/pail` - log viewer.
- `laravel/pint` - code formatting.
- `laravel/sail` - Docker environment support.
- `mockery/mockery` - mocking library.
- `nunomaduro/collision` - error display in CLI.
- `pestphp/pest` and `pestphp/pest-plugin-laravel` - testing framework.

### What is Central vs Optional
- Central: `laravel/framework`, `sanctum`, `socialite`, `spatie/laravel-permission`, `voku/anti-xss`.
- Optional: `tinker`, `pail`, `pint`, `sail`, `mockery`, `pest`.

## 4. Project Commands and Local Setup

Confirmed commands from `composer.json` and typical Laravel usage:
- `composer install`
- `npm install`
- `php artisan migrate`
- `php artisan db:seed`
- `php artisan serve`
- `php artisan test`
- `php artisan storage:link`

Additional commands implied by the project:
- `php artisan config:clear`
- `php artisan package:discover`

From `composer.json` scripts:
- `composer dev` runs `php artisan serve`, `php artisan queue:listen --tries=1`, and `npm run dev` in parallel.
- `composer test` runs `php artisan config:clear --ansi` and `php artisan test`.

## 5. Environment Variables and Configuration

The project includes `.env.example` and uses standard Laravel config files. The following env variables are present or referenced:

### Core App
- `APP_NAME` - app name.
- `APP_ENV` - environment mode, e.g. `local`.
- `APP_KEY` - application encryption key.
- `APP_DEBUG` - debug mode.
- `APP_URL` - base URL for the backend.

### Frontend and Cookies
- `FRONTEND_URL` - used in `app/Http/Middleware/Authenticate.php` to redirect web requests when unauthenticated.
- `SESSION_DOMAIN` - used by `config/session.php` to scope the session cookie.
- `SESSION_SECURE_COOKIE` - used by `config/session.php` to enable HTTPS-only cookies.
- `SESSION_SAME_SITE` - same-site policy, default `lax`.
- `SANCTUM_STATEFUL_DOMAINS` - used in `config/sanctum.php` for SPA stateful authentication.
- `CORS_ALLOWED_ORIGINS` - checked by `app/Http/Middleware/Cors.php`.

### Database
- `DB_CONNECTION` - database driver, default `pgsql` in `.env.example`.
- `DB_HOST` - database host.
- `DB_PORT` - database port.
- `DB_DATABASE` - database name.
- `DB_USERNAME` - database user.
- `DB_PASSWORD` - database password.

### Google OAuth
- `GOOGLE_CLIENT_ID` - Google OAuth client ID.
- `GOOGLE_CLIENT_SECRET` - Google OAuth client secret.
- `GOOGLE_REDIRECT_URI` - Google OAuth callback URI, configured in `config/services.php`.

### Mail / Notification
- `MAIL_MAILER`
- `MAIL_SCHEME`
- `MAIL_HOST`
- `MAIL_PORT`
- `MAIL_USERNAME`
- `MAIL_PASSWORD`
- `MAIL_FROM_ADDRESS`
- `MAIL_FROM_NAME`

### Cache / Queue / Filesystem
- `FILESYSTEM_DISK` - default disk for file storage, used by `config/filesystems.php`.
- `QUEUE_CONNECTION` - queue driver.
- `CACHE_STORE` - cache driver.
- `SESSION_DRIVER` - session driver, set to `database` in `.env.example`.
- `SESSION_CONNECTION` - optional session database connection.
- `SESSION_TABLE` - session table name `sessions`.

### Local vs Production
- Local values are expected to use `localhost` and ports like `3000`, `5173`, `5174`, `5175`.
- Production values should use actual frontend/backends URLs and HTTPS.
- Misconfiguring `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`, or CORS origins will break login/session behavior.
- Incorrect `GOOGLE_REDIRECT_URI` will break Google OAuth.

## 6. Full Backend File and Folder Structure

The main backend structure is:

```
app/
  Http/
    Controllers/
      Api/
        AuthController.php
        EventController.php
        CategoryController.php
        SubCategoryController.php
        RecommendationController.php
    Middleware/
      Authenticate.php
      Cors.php
      RedirectIfAuthenticated.php
    Kernel.php
  Models/
    User.php
    Event.php
    Category.php
    SubCategory.php
    Organizer.php
    EventAddress.php
    TicketType.php
    Faq.php
    EventOption.php
    EventLike.php
    EventView.php
    Purchase.php
  Providers/
    AppServiceProvider.php
    AuthServiceProvider.php
    RouteServiceProvider.php
  Services/
    RecommendationEngine.php
bootstrap/
  app.php
  providers.php
config/
  app.php
  auth.php
  cors.php
  sanctum.php
  session.php
  services.php
  permission.php
  filesystems.php
  ...
database/
  migrations/
    0001_01_01_000000_create_users_table.php
    0001_01_01_000001_create_cache_table.php
    0001_01_01_000002_create_jobs_table.php
    2025_04_24_014414_create_personal_access_tokens_table.php
    2025_04_24_033448_create_event_addresses_table.php
    2025_04_24_033449_create_organizers_table.php
    2025_04_24_033450_create_categories_table.php
    2025_04_24_033452_create_events_table.php
    2025_04_24_033456_create_sub_categories_table.php
    2025_04_24_033457_create_ticket_types_table.php
    2025_04_24_033458_create_faqs_table.php
    2025_04_24_033459_create_event_options_table.php
    2025_04_24_033460_create_event_sub_category_table.php
    2025_04_24_045144_create_permission_tables.php
    2025_11_19_011014_add_google_fields_to_users_table.php
    2025_11_27_050041_add_status_to_events_table.php
    2025_11_30_051332_create_event_views_table.php
    2025_11_30_051333_create_event_likes_table.php
    2025_11_30_051335_create_purchases_table.php
  factories/
    UserFactory.php
    EventFactory.php
    CategoryFactory.php
    SubCategoryFactory.php
    OrganizerFactory.php
    EventAddressFactory.php
    TicketTypeFactory.php
    FaqFactory.php
    EventOptionFactory.php
  seeders/
    DatabaseSeeder.php
    CategorySeeder.php
    SubCategorySeeder.php
    EventSeeder.php
routes/
  api.php
  web.php
  console.php
resources/
  views/
    welcome.blade.php
public/
  index.php
tests/
  Pest.php
  TestCase.php
  Feature/ExampleTest.php
  Unit/ExampleTest.php
artisan
composer.json
package.json
vite.config.js
phpunit.xml
.env.example
```

### Folder Responsibilities
- `app/Http/Controllers/Api/`: API request handling and business logic.
- `app/Models/`: Eloquent models and relationships.
- `app/Services/`: specialized business logic outside controllers, currently recommendations.
- `app/Http/Middleware/`: custom request handling, CORS, auth redirect.
- `config/`: Laravel configuration for auth, sessions, CORS, services, filesystem, permission, Sanctum.
- `database/migrations/`: schema definition for application and framework tables.
- `database/factories/`: seed/test data generators.
- `database/seeders/`: application seed logic.
- `routes/`: HTTP route definitions.
- `tests/`: minimal Pest test scaffolding.

## 7. Application Boot Flow

The Laravel backend boot sequence is:

1. `public/index.php` receives the HTTP request.
2. It loads `bootstrap/app.php` and creates the `Illuminate\Foundation\Application` instance.
3. `bootstrap/app.php` configures routing and middleware via `Application::configure()`.
   - It prepends `\App\Http\Middleware\Cors::class` globally.
   - It aliases `cors` to the same class.
   - It configures the `api` middleware group to include `EnsureFrontendRequestsAreStateful`, throttle, and `SubstituteBindings`.
4. The router resolves the request and matches it to a route defined in `routes/api.php` or `routes/web.php`.
5. Route middleware is applied via `app/Http/Kernel.php`.
   - Global middleware includes `Cors`, `HandleCors`, request trimming, and string-to-null conversion.
   - The `api` middleware group includes request throttling and route model binding.
   - `web` middleware includes session, CSRF protection, and cookie encryption.
6. The matched controller action executes.
   - Controllers use models, validation, services, and DB transactions.
7. A JSON response is returned for API routes, or a Blade view for web routes.

### Service Providers
- `App\Providers\AppServiceProvider` forces HTTPS when `config('app.env') === 'production'`.
- `App\Providers\AuthServiceProvider` registers authorization policies, but no policies are currently defined.
- `App\Providers\RouteServiceProvider` registers the `api` and `web` route groups and configures the `api` rate limiter to 60 requests per minute.

### Relevant Provider Code
- `RouteServiceProvider::boot()` binds `routes/api.php` under `api` middleware and `routes/web.php` under `web` middleware.
- `AppServiceProvider::boot()` may force HTTPS in production.
- `AuthServiceProvider::boot()` simply calls `$this->registerPolicies()`.

## 8. Route Architecture

### Full Route Map

#### Health / Test Routes
- `GET /api/ping`
  - File: `routes/api.php`
  - Returns `{ message: 'pong', success: true }`.
  - No auth.
- `GET /api/cors-test`
  - File: `routes/api.php`
  - Returns origin, method, time.
  - No auth.

#### Authentication Routes
- `GET /sanctum/csrf-cookie`
  - Laravel Sanctum controller `CsrfCookieController@show`.
  - Used by frontend to initialize CSRF cookie.
  - No auth.
- `POST /api/login`
  - Controller: `App\Http\Controllers\Api\AuthController@login`
  - Validates `email` and `password`.
  - Uses `Auth::attempt()`.
  - Regenerates session.
  - Returns `{ user, message }` on success.
  - Throws 422 validation error on bad credentials.
- `POST /api/register`
  - Controller: `AuthController@register`
  - Validates `name`, `email`, `password`, `password_confirmation`.
  - Creates `User` with hashed password.
  - Logs in user and regenerates session.
  - Returns 201 with `{ user, message }`.
- `POST /api/logout`
  - Controller: `AuthController@logout`
  - Middleware: `auth:sanctum`
  - Logs out user, invalidates session, regenerates token.
  - Returns `{ message }`.
- `GET /api/user`
  - Controller: `AuthController@user`
  - Middleware: `auth:sanctum`
  - Returns current authenticated user.
- `GET /api/auth/google`
  - Controller: `AuthController@redirectToGoogle`
  - Middleware: `web`
  - Initiates Google OAuth via Socialite.
- `GET /api/auth/google/callback`
  - Controller: `AuthController@handleGoogleCallback`
  - Middleware: `web`
  - Completes Google OAuth login.

#### Event Routes
- `GET /api/events`
  - Controller: `EventController@index`
  - No auth required.
  - Query parameters: `category_id`, `subcategory_id`, `date_from`, `date_to`, `featured`, `sort`, `per_page`, `page`.
  - Returns paginated event list with `category`, `organizer`, `address`, `subCategories`.
  - Response includes `data`, `meta`, and `links`.
- `GET /api/events/{event}`
  - Controller: `EventController@show`
  - No auth.
  - Loads event relations: `category`, `organizer`, `address`, `subCategories`, `ticketTypes`, `faqs`, `eventOptions`.
- `POST /api/events`
  - Controller: `EventController@store`
  - Middleware: `auth:sanctum`
  - Requires user to pass `hasAnyRole(['admin', 'agent'])`.
  - Creates event, organizer, address, ticket types, faqs, event options.
  - Returns 201 with created event ID.
- `PUT /api/events/{event}`
  - Controller: `EventController@update`
  - Middleware: `auth:sanctum`
  - Requires `hasAnyRole(['admin', 'agent'])` OR owner (`created_by`).
  - Supports partial update and image replacement.
- `DELETE /api/events/{event}`
  - Controller: `EventController@destroy`
  - Middleware: `auth:sanctum`
  - Requires `hasAnyRole(['admin'])` OR owner.
  - Deletes images and the event.
- `POST /api/events/{event}/track-view`
  - Controller: `EventController@trackView`
  - Middleware: `throttle:60,1`
  - Tracks authenticated users only; returns success true/false.
- `POST /api/events/{event}/toggle-like`
  - Controller: `EventController@toggleLike`
  - Middleware: `auth:sanctum`
  - Toggles like/unlike and clears recommendation cache on like.
- `GET /api/events/{event}/check-liked`
  - Controller: `EventController@checkLiked`
  - Middleware: `auth:sanctum`
  - Returns `liked` boolean and `likes_count`.
- `GET /api/user/liked-events`
  - Controller: `EventController@getLikedEvents`
  - WARNING: route has no `auth:sanctum` guard in `routes/api.php`, but the controller assumes auth.

#### Category Routes
- `GET /api/categories`
  - Controller: `CategoryController@index`
  - Returns all categories.
- `GET /api/subcategories`
  - Controller: `SubCategoryController@index`
  - Returns all subcategories.

#### Recommendation Routes
- `GET /api/recommendations`
  - Controller: `RecommendationController@index`
  - Middleware: `auth:sanctum`
  - Returns cached recommendations for authenticated user.
- `POST /api/recommendations/clear-cache`
  - Controller: `RecommendationController@clearCache`
  - Middleware: `auth:sanctum`
  - Clears recommendation cache for authenticated user.

#### Purchase Routes
- Purchase routes are not found in current codebase.
- The `Purchase` model and `purchases` table exist, but there is no controller or API route exposing purchase creation or management.

## 9. Controller-by-Controller Deep Dive

### AuthController

File: `app/Http/Controllers/Api/AuthController.php`
Namespace: `App\Http\Controllers\Api`
Imports:
- `App\Http\Controllers\Controller`
- `Illuminate\Http\Request`
- `Illuminate\Support\Facades\Auth`
- `Illuminate\Validation\ValidationException`
- `Illuminate\Support\Facades\Hash`
- `App\Models\User`
- `Laravel\Socialite\Facades\Socialite`
- `Illuminate\Support\Str`

#### Purpose
Handles user authentication, registration, logout, current user retrieval, and Google OAuth.

#### login(Request)
- Called by `POST /api/login`.
- Validation:
  - `email`: required, email.
  - `password`: required.
- Uses `Auth::attempt($request->only('email', 'password'))`.
- If successful:
  - regenerates session with `$request->session()->regenerate()`.
  - returns JSON `{ user: Auth::user(), message: 'Login successful' }`.
- If authentication fails:
  - throws `ValidationException` with `Wrong email or password`.
- Risks: if session and CSRF are not set correctly, login can fail for frontend SPA.

#### register(Request)
- Called by `POST /api/register`.
- Validation:
  - `name`: required, string, max 255.
  - `email`: required, string, email, max 255, unique in `users`.
  - `password`: required, string, min 8, confirmed.
- Creates a new `User` using `User::create()`.
- Password is hashed with `Hash::make()`.
- Logs in user with `Auth::login($user)`.
- Regenerates session.
- Returns 201 JSON `{ user, message: 'Registration successful' }`.
- If validation fails, returns 422 errors.

#### logout(Request)
- Called by `POST /api/logout`.
- Middleware: `auth:sanctum`.
- Logs out via `Auth::guard('web')->logout()`.
- Invalidates session and regenerates CSRF token.
- Returns `{ message: 'Logged out successfully' }`.
- If called without auth, `auth:sanctum` prevents access.

#### user(Request)
- Called by `GET /api/user`.
- Middleware: `auth:sanctum`.
- Returns the authenticated user model directly.

#### redirectToGoogle(Request)
- Called by `GET /api/auth/google`.
- Middleware: `web`.
- Ensures session `_token` exists and saves session.
- Uses `Socialite::driver('google')->stateless()`.
- In local environment, disables SSL verification with Guzzle client.
- Redirects user to Google OAuth consent page.
- On exception, redirects to `http://localhost:5173/login?error=google_auth_failed&message=...`.
- Risk: hardcoded redirect URLs and local SSL bypass are production risks.

#### handleGoogleCallback(Request)
- Called by `GET /api/auth/google/callback`.
- Middleware: `web`.
- Uses `Socialite::driver('google')->stateless()` and local SSL bypass if `app.env` is local.
- Retrieves Google user profile.
- Uses `User::updateOrCreate` by email, storing:
  - `name`
  - `google_id`
  - `avatar`
  - `password` as random hashed string
  - `email_verified_at` as now()
- Logs in user, regenerates session.
- Encodes user data and redirects to `http://localhost:5173/auth/google/success?user=...`.
- The returned user data includes `role`, though the `users` table does not define `role`.
- On failure, redirects to login with error message.

### EventController

File: `app/Http/Controllers/Api/EventController.php`
Namespace: `App\Http\Controllers\Api`
Imports:
- `App\Http\Controllers\Controller`
- `App\Models\Event`
- `App\Models\Organizer`
- `App\Models\EventAddress`
- `App\Models\TicketType`
- `App\Models\Faq`
- `App\Models\EventOption`
- `Illuminate\Http\Request`
- `Illuminate\Support\Facades\DB`
- `Illuminate\Support\Facades\Storage`
- `Voku\Helper\AntiXSS` (unused)

#### index(Request)
- Called by `GET /api/events`.
- No auth required.
- Builds a query on `Event::with(['category', 'organizer', 'address', 'subCategories'])`.
- Filters:
  - `category_id` exact match on `events.category_id`.
  - `subcategory_id` via `whereHas('subCategories')` on sub_categories.id.
  - `date_from` filter on `events.date >= date_from`.
  - `date_to` filter on `events.date <= date_to`.
  - `featured` if truthy.
- Sorting by query param `sort`:
  - `date-asc`, `date-desc`, `price-asc`, `price-desc`, default uses `latest()`.
- Pagination:
  - `per_page` defaults to 10.
  - Max page size enforced at 100.
- Logs query info with `
  Log::info('Events query', ['total_count' => Event::count(), 'query_count' => $query->count(), ...])`.
  - Note: this logs a separate full-count query and `count()` on the filtered query.
- Returns JSON `{ data: events->items(), meta: {...}, links: {...} }`.
- Response shape is a manual pagination wrapper.

#### show(Event $event)
- Called by `GET /api/events/{event}`.
- No auth required.
- Uses route model binding.
- Loads relations: `category`, `organizer`, `address`, `subCategories`, `ticketTypes`, `faqs`, `eventOptions`.
- Returns JSON `{ data: $event }`.

#### store(Request)
- Called by `POST /api/events`.
- Middleware: `auth:sanctum`.
- Authorization:
  - Checks `auth()->user()->hasAnyRole(['admin', 'agent'])`.
  - If false, returns 403 unauthorized.
  - WARNING: this requires `hasAnyRole()` to exist on User.
- Validation rules:
  - `title`, `description`, `category_id`, `organizer.name`, organizer email optional.
  - `location.*` fields required.
  - `date` must be after today.
  - `price`, `total_tickets`, `duration`, `featured`.
  - `main_image`, `banner_image` required image files, `jpeg,png,gif`, max 5MB.
  - `sub_categories` array max 5, each exists in `sub_categories`.
  - `ticket_types` required array min 1; each has `name`, `price`, `quantity`, optional description/sales_end_date/is_featured.
  - `ticket_types.*.sales_end_date` must be before or equal to `date`.
  - `faqs` optional array with question/answer strings.
  - `event_options` optional string array.
- Transaction:
  - Uses `DB::transaction` to create organizer, address, event, ticket types, faqs, and event options.
- Image storage:
  - `main_image` and `banner_image` are stored under `events/images` on the `public` disk.
- Event creation:
  - Saves event with `created_by` from `auth()->id()`.
- Subcategories:
  - `subCategories()->sync(...)` if provided.
- Ticket types:
  - Creates `TicketType` records.
- FAQs:
  - Creates `Faq` records only when question and answer are both present.
- Event options:
  - Uses `EventOption::firstOrCreate(['name' => $optionName], ['is_custom' => true])`.
  - Attaches to event by pivot.
- Return:
  - 201 JSON with `{ data: { id, message } }`.
- Failure:
  - Returns 500 with `{ error: 'Failed to create event', message: e->getMessage() }`.

#### update(Request, Event)
- Called by `PUT /api/events/{event}`.
- Middleware: `auth:sanctum`.
- Authorization:
  - Allows `hasAnyRole(['admin', 'agent'])` OR event owner `auth()->id() === $event->created_by`.
  - WARNING: lacking proper role support means this can fail unexpectedly.
- Validation:
  - Supports partial update with `sometimes|required` rules for title, description, category_id, date, price, total_tickets.
  - `main_image` and `banner_image` can be replaced.
  - `sub_categories` can be synced.
- Image replacement:
  - Deletes the old public storage path if present.
  - Stores new image to `events/images` on the `public` disk.
- Updates event with validated fields.
- Syncs subcategories if provided.
- Reloads relations and returns updated event JSON with message.
- Failure returns 500 with error message.

#### destroy(Event)
- Called by `DELETE /api/events/{event}`.
- Middleware: `auth:sanctum`.
- Authorization:
  - Allows `hasAnyRole(['admin'])` OR owner.
  - This is stricter than store/update.
- Deletes stored image files for main and banner.
- Deletes the event record within a transaction.
- Returns `{ data: null, message: 'Event deleted successfully' }`.
- Failure returns 500.

#### trackView(Event)
- Called by `POST /api/events/{event}/track-view`.
- Middleware: `throttle:60,1`.
- If `auth()->check()` is true, calls `App\Models\EventView::trackView(auth()->id(), $event->id, request()->ip())`.
- Returns `{ success: true }`.
- On exception, logs a warning and returns `{ success: false }`.
- Note: this route does not explicitly require auth, but only tracks when auth exists.

#### toggleLike(Event)
- Called by `POST /api/events/{event}/toggle-like`.
- Middleware: `auth:sanctum`.
- If unauthenticated, returns 401 with message.
- Uses `EventLike::toggleLike(auth()->id(), $event->id)`.
- If liked, clears recommendation cache by instantiating `RecommendationEngine`.
- Returns `{ liked, likes_count, message }`.
- On exception, logs error and returns 500.

#### checkLiked(Event)
- Called by `GET /api/events/{event}/check-liked`.
- Middleware: `auth:sanctum`.
- If unauthenticated, returns `{ liked: false, likes_count: 0 }`.
- Otherwise returns like existence and current likes count.

#### getLikedEvents(Request)
- Called by `GET /api/user/liked-events`.
- WARNING: route lacks authentication middleware.
- It assumes `$request->user()` exists.
- Retrieves liked event IDs and loads events with `category`, `address`, and `ticketTypes`.
- Returns `{ data: events }`.

### CategoryController

File: `app/Http/Controllers/Api/CategoryController.php`
Namespace: `App\Http\Controllers\Api`
Imports: `Category`, `Request`

#### index(Request)
- Called by `GET /api/categories`.
- Returns all categories with no filters.
- Response JSON: `{ data: categories }`.

### SubCategoryController

File: `app/Http/Controllers/Api/SubCategoryController.php`
Namespace: `App\Http\Controllers\Api`
Imports: `SubCategory`, `Request`

#### index(Request)
- Called by `GET /api/subcategories`.
- Returns all subcategories.
- Response JSON: `{ data: subCategories }`.

### RecommendationController

File: `app/Http/Controllers/Api/RecommendationController.php`
Namespace: `App\Http\Controllers\Api`
Imports:
- `RecommendationEngine`
- `Log`

#### index()
- Called by `GET /api/recommendations`.
- Middleware: `auth:sanctum`.
- If unauthenticated, returns 401.
- Instantiates `RecommendationEngine(auth()->user())`.
- Calls `getRecommendations()`.
- Logs counts for each recommendation bucket.
- Returns 200 JSON:
  - `data`: recommendation payload,
  - `meta`: `generated_at`, `cache_ttl`.
- On exception, logs error and returns 500.

#### clearCache()
- Called by `POST /api/recommendations/clear-cache`.
- Middleware: `auth:sanctum`.
- If unauthenticated, returns 401.
- Instantiates `RecommendationEngine(auth()->user())` and calls `clearCache()`.
- Returns success message.

## 10. Model-by-Model Deep Dive

### User Model

File: `app/Models/User.php`
Table: `users`
Fillable:
- `name`
- `email`
- `password`
Hidden:
- `password`
- `remember_token`
Casts: defined through `protected function casts(): array` returning `email_verified_at` and `password`.

#### Notes
- The User model does not define a `role` attribute.
- It does not use `Spatie\Permission\Traits\HasRoles`.
- Therefore role-check calls like `hasAnyRole()` are unsupported in current codebase.
- The `casts()` method is non-standard for Laravel; typically `protected $casts` is used. This may mean `email_verified_at` and `password` casting is not actually applied.

### Event Model

File: `app/Models/Event.php`
Table: `events`
Fillable fields:
- `title`
- `description`
- `category_id`
- `organizer_id`
- `address_id`
- `date`
- `price`
- `total_tickets`
- `duration`
- `featured`
- `main_image`
- `banner_image`
- `created_by`

Casts:
- `date` as `datetime`
- `price` as `decimal:2`
- `featured` as `boolean`

Relationships:
- `category()`: belongsTo `Category`
- `organizer()`: belongsTo `Organizer`
- `address()`: belongsTo `EventAddress` via `address_id`
- `createdBy()`: belongsTo `User` via `created_by`
- `ticketTypes()`: hasMany `TicketType`
- `faqs()`: hasMany `Faq`
- `subCategories()`: belongsToMany `SubCategory`
- `eventOptions()`: belongsToMany `EventOption`

### Category Model

File: `app/Models/Category.php`
Table: `categories`
Fillable:
- `name`

Relationships:
- `events()`: hasMany `Event`

Usage:
- Used by the category list endpoint and event filtering.

### SubCategory Model

File: `app/Models/SubCategory.php`
Table: `sub_categories`
Fillable:
- `name`

Relationships:
- `events()`: belongsToMany `Event`

Usage:
- Used by subcategory list endpoint and event filtering via `whereHas('subCategories')`.

### Organizer Model

File: `app/Models/Organizer.php`
Table: `organizers`
Fillable:
- `name`
- `email`

Relationships:
- `events()`: hasMany `Event`

Usage:
- Created in `EventController@store` and referenced by events.

### EventAddress Model

File: `app/Models/EventAddress.php`
Table: `event_addresses`
Fillable:
- `venue_name`
- `address_line1`
- `address_line2`
- `city`
- `state`
- `postal_code`
- `country`

Relationships:
- `events()`: hasMany `Event` via `address_id`

Usage:
- Created during event creation.

### TicketType Model

File: `app/Models/TicketType.php`
Table: `ticket_types`
Fillable:
- `event_id`
- `name`
- `price`
- `quantity`
- `description`
- `sales_end_date`
- `is_featured`

Casts:
- `price` as `decimal:2`
- `quantity` as `integer`
- `sales_end_date` as `date`
- `is_featured` as `boolean`

Relationships:
- `event()`: belongsTo `Event`

Usage:
- Created during event creation.
- Loaded in event detail response.

### Faq Model

File: `app/Models/Faq.php`
Table: `faqs`
Fillable:
- `event_id`
- `question`
- `answer`

Relationships:
- `event()`: belongsTo `Event`

Usage:
- Created during event creation and served in event detail response.

### EventOption Model

File: `app/Models/EventOption.php`
Table: `event_options`
Fillable:
- `name`
- `is_custom`

Casts:
- `is_custom` as `boolean`

Relationships:
- `events()`: belongsToMany `Event`

Usage:
- Created/attached during event creation.
- Allows custom metadata via many-to-many pivot `event_event_option`.

### EventLike Model

File: `app/Models/EventLike.php`
Table: `event_likes`
Fillable:
- `user_id`
- `event_id`

Casts:
- `user_id` as integer
- `event_id` as integer
- `created_at` as datetime

Constants:
- `UPDATED_AT = null` disables update timestamps.

Relationships:
- `user()`: belongsTo `User`
- `event()`: belongsTo `Event`

Helper methods:
- `toggleLike($userId, $eventId)` creates or deletes a like.
- `isLiked($userId, $eventId)` checks existence.

Usage:
- Used in `EventController@toggleLike`, `checkLiked`, `getLikedEvents`, and `RecommendationEngine`.

### EventView Model

File: `app/Models/EventView.php`
Table: `event_views`
Fillable:
- `user_id`
- `event_id`
- `viewed_at`
- `ip_address`

Casts:
- `viewed_at` as datetime
- `user_id` as integer
- `event_id` as integer

Constants:
- `UPDATED_AT = null`
- `CREATED_AT = 'viewed_at'`

Relationships:
- `user()`: belongsTo `User`
- `event()`: belongsTo `Event`

Helper methods:
- `trackView($userId, $eventId, $ipAddress)` prevents duplicate views within one hour and saves a new record.

Usage:
- Used in `EventController@trackView`.
- Used by `RecommendationEngine`.

### Purchase Model

File: `app/Models/Purchase.php`
Table: `purchases`
Fillable:
- `user_id`
- `event_id`
- `ticket_type_id`
- `quantity`
- `total_price`
- `status`
- `payment_method`
- `transaction_id`

Casts:
- `total_price` as `decimal:2`
- `quantity` as integer
- `user_id`, `event_id`, `ticket_type_id` as integers
- timestamps as datetime

Constants:
- `STATUS_PENDING`
- `STATUS_COMPLETED`
- `STATUS_CANCELLED`
- `STATUS_REFUNDED`

Scopes:
- `scopeCompleted($query)` filters completed purchases.
- `scopeForUser($query, int $userId)` filters by user.
- `scopeForEvent($query, int $eventId)` filters by event.

Relationships:
- `user()`: belongsTo `User`
- `event()`: belongsTo `Event`
- `ticketType()`: belongsTo `TicketType`

Usage:
- Used by `RecommendationEngine` to exclude purchased events.
- No purchase controller or routes currently exist.

## 11. Database Schema and Migration Deep Dive

### `0001_01_01_000000_create_users_table.php`
- Creates `users`, `password_reset_tokens`, `sessions`.
- `users` fields: `id`, `name`, `email` unique, `email_verified_at`, `password`, `remember_token`, timestamps.
- No `role`, `google_id`, or `avatar` fields at creation.
- `sessions` table supports `SESSION_DRIVER=database`.

### `2025_11_19_011014_add_google_fields_to_users_table.php`
- Adds `google_id` nullable after `email`.
- Adds `avatar` nullable after `google_id`.
- No role field added.

### `2025_04_24_033450_create_categories_table.php`
- Creates `categories` with `id`, `name`, timestamps.

### `2025_04_24_033456_create_sub_categories_table.php`
- Creates `sub_categories` with `id`, `name`, timestamps.

### `2025_04_24_033449_create_organizers_table.php`
- Creates `organizers` with `id`, `name`, `email` unique nullable, timestamps.

### `2025_04_24_033448_create_event_addresses_table.php`
- Creates `event_addresses` with venue and address fields plus timestamps.

### `2025_04_24_033452_create_events_table.php`
- Creates `events` with:
  - `id`, `title`, `description`, `category_id`, `organizer_id`, `address_id`, `date`, `price`, `total_tickets`, `duration`, `featured`, `main_image`, `banner_image`, `created_by`, timestamps.
- Foreign keys cascade on delete for category, organizer, address, and created_by.
- No `status` column initially.

### `2025_11_27_050041_add_status_to_events_table.php`
- Adds `status` enum `['draft','active','cancelled','completed']` default `active`.
- Adds index `idx_event_status`.
- Updates all existing events to `active`.
- Note: this migration uses `DB::table()` without importing `DB`.

### `2025_04_24_033457_create_ticket_types_table.php`
- Creates `ticket_types` with `event_id`, `name`, `price`, `quantity`, `description`, `sales_end_date`, `is_featured`, timestamps.
- Cascade deletes on `event_id`.

### `2025_04_24_033458_create_faqs_table.php`
- Creates `faqs` with `event_id`, `question`, `answer`, timestamps.
- Cascade deletes on `event_id`.

### `2025_04_24_033459_create_event_options_table.php`
- Creates `event_options` with `name`, `is_custom`, timestamps.
- Creates pivot `event_event_option` with `event_id`, `event_option_id` primary key.
- Cascade deletes.

### `2025_04_24_033460_create_event_sub_category_table.php`
- Creates pivot `event_sub_category` with `event_id`, `sub_category_id` primary key.
- Cascade deletes.

### `2025_04_24_045144_create_permission_tables.php`
- Creates Spatie permission tables: `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`.
- Uses config values from `config/permission.php`.
- The package is available but user model currently does not implement its trait.

### `2025_11_30_051332_create_event_views_table.php`
- Creates `event_views` with `user_id`, `event_id`, `viewed_at`, `ip_address`.
- Indexes:
  - `idx_user_event_viewed` on `(user_id,event_id,viewed_at)`,
  - `idx_event_viewed` on `(event_id,viewed_at)`,
  - `idx_user` on `user_id`.
- Cascade delete by user and event.

### `2025_11_30_051333_create_event_likes_table.php`
- Creates `event_likes` with `user_id`, `event_id`, `created_at`.
- Unique constraint `unique_user_event_like` on `(user_id,event_id)`.
- Indexes on `user_id` and `event_id`.
- Cascade delete by user and event.

### `2025_11_30_051335_create_purchases_table.php`
- Creates `purchases` with `user_id`, `event_id`, `ticket_type_id`, `quantity`, `total_price`, `status`, `payment_method`, `transaction_id`, timestamps.
- Status enum values: `pending`, `completed`, `cancelled`, `refunded`.
- `transaction_id` unique.
- Indexes: `idx_user_event_purchase`, `idx_user_created`, `idx_status`, `idx_event_purchase`.
- Foreign keys cascade on user and event, set null on ticket_type if ticket removed.

### Framework Tables
- `password_reset_tokens`
- `sessions`
- `cache` from `0001_01_01_000001_create_cache_table.php`
- `jobs` from `0001_01_01_000002_create_jobs_table.php`
- `personal_access_tokens` from `2025_04_24_014414_create_personal_access_tokens_table.php`

### Entity Relationship Narrative
- `users` create and own sessions.
- `events` belong to `categories`, `organizers`, `event_addresses`, and `users` via `created_by`.
- `events` have many `ticket_types`, `faqs`, and many-to-many `sub_categories` and `event_options`.
- `event_likes` and `event_views` track user-event interaction.
- `purchases` store ticket purchases and link users to events and ticket types.
- Spatie tables provide RBAC infrastructure, but are not fully wired to the `User` model.

## 12. Backend Data Models and API Payload Shapes

### Event List Response
From `EventController@index`:
```json
{
  "data": [ /* event objects */ ],
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 2,
    "per_page": 10,
    "to": 10,
    "total": 12
  },
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": "..."
  }
}
```
- Each event object includes the event fields from the `events` table and loaded relations from `with()`.

### Single Event Response
From `EventController@show`:
```json
{
  "data": {
    "id": 1,
    "title": "...",
    "description": "...",
    "category": { ... },
    "organizer": { ... },
    "address": { ... },
    "subCategories": [ ... ],
    "ticketTypes": [ ... ],
    "faqs": [ ... ],
    "eventOptions": [ ... ]
  }
}
```
- Loaded relations ensure frontend receives category, organizer, address, subcategories, ticket types, FAQs, and options in one response.

### Event Create Request
Expected fields in `POST /api/events`:
- `title`
- `description`
- `category_id`
- `organizer.name`
- `organizer.email` (nullable)
- `location.venue_name`
- `location.address_line1`
- `location.address_line2`
- `location.city`
- `location.state`
- `location.postal_code`
- `location.country`
- `date`
- `price`
- `total_tickets`
- `duration` (nullable)
- `featured` (boolean)
- `main_image` file upload
- `banner_image` file upload
- `sub_categories` array of IDs
- `ticket_types` array of objects with `name`, `price`, `quantity`, `description`, `sales_end_date`, `is_featured`
- `faqs` array of question/answer pairs
- `event_options` array of strings

### Event Update Request
Expected fields in `PUT /api/events/{event}`:
- Any of: `title`, `description`, `category_id`, `date`, `price`, `total_tickets`, `duration`, `featured`, `main_image`, `banner_image`, `sub_categories`.
- Partial updates are allowed via `sometimes|required` rules.

### Like Toggle Response
From `EventController@toggleLike`:
```json
{
  "liked": true,
  "likes_count": 5,
  "message": "Event liked"
}
```
- If unliked, `liked` is false and message is `Event unliked`.

### Check Liked Response
From `EventController@checkLiked`:
```json
{
  "liked": false,
  "likes_count": 3
}
```
- Unauthenticated callers receive false and 0.

### Track View Response
From `EventController@trackView`:
```json
{ "success": true }
```
- If tracking fails, still returns HTTP 200 with `success: false`.

### Recommendation Response
From `RecommendationController@index`:
```json
{
  "data": {
    "because_you_liked": { "label": "Because you liked...", "count": 0, "events": [ ... ] },
    "jump_back_in": { ... },
    "made_for_you": { ... }
  },
  "meta": {
    "generated_at": "...",
    "cache_ttl": "30 minutes"
  }
}
```
- Each bucket returns `label`, `count`, and `events` array.

### Login Response
From `AuthController@login`:
```json
{ "user": { ... }, "message": "Login successful" }
```

### Register Response
From `AuthController@register`:
```json
{ "user": { ... }, "message": "Registration successful" }
```

### Google OAuth Redirect / Callback Response
From `AuthController@handleGoogleCallback`:
- The method redirects to `http://localhost:5173/auth/google/success?user=...`.
- It does not return a JSON payload.
- The callback encodes `{ id, name, email, role, avatar }` in a query string.
- Note: the returned `role` field is likely `null` or undefined because `User` has no role attribute.

## 13. Authentication System Deep Dive

### Sanctum Session-Based Auth Flow
The code uses Laravel Sanctum for SPA/session auth.

1. Frontend requests `GET /sanctum/csrf-cookie`.
2. Laravel returns a CSRF cookie.
3. Frontend sends login/register requests with credentials and cookies.
4. `AuthController@login` uses `Auth::attempt()` to validate credentials.
5. On success, the backend regenerates session and returns the authenticated user.
6. The browser stores the session cookie and uses it for future authenticated requests.
7. `GET /api/user` returns the current authenticated user.
8. `POST /api/logout` invalidates the session and regenerates the CSRF token.

Key files:
- `routes/api.php` for auth routes.
- `app/Http/Controllers/Api/AuthController.php` for login/register/logout/user.
- `config/sanctum.php` for stateful domains.
- `config/session.php` for cookie settings.
- `app/Http/Kernel.php` and `bootstrap/app.php` for middleware.

### Bearer Token Support
- The project includes `personal_access_tokens` migration via Laravel Sanctum.
- `config/sanctum.php` guard includes `web`, and bearer tokens are supported by default.
- No code currently issues or consumes personal access tokens in controllers.
- Therefore bearer token support is present in schema but not actively used by API flows.

### Google OAuth Flow
1. Frontend hits `GET /api/auth/google`.
2. `AuthController@redirectToGoogle` starts the Socialite flow.
3. The backend optionally disables SSL verification when `app.env` is `local`.
4. Google redirects back to `GET /api/auth/google/callback`.
5. `AuthController@handleGoogleCallback` retrieves Google user data.
6. `User::updateOrCreate()` creates or updates the user record by email.
7. The backend stores `google_id`, `avatar`, and a random hashed password.
8. It logs in the user and regenerates session.
9. It redirects to `http://localhost:5173/auth/google/success?user=...`.

Production risk:
- local SSL bypass is active in local environment and must not be used in production.
- The redirect URL is hardcoded to `localhost:5173`, which is not suitable for production.

### Auth Files Involved
- `AuthController.php`
- `User.php`
- `config/auth.php`
- `config/sanctum.php`
- `config/services.php`
- `app/Http/Middleware/Authenticate.php`
- `routes/api.php`

### Security Warnings
- Never expose session cookies to third-party sites.
- Configure `SANCTUM_STATEFUL_DOMAINS` precisely for frontend domains.
- Configure CORS origins carefully; do not allow wildcard with credentials.
- Use HTTPS in production and set `SESSION_SECURE_COOKIE=true`.
- Remove or change local OAuth redirect hardcoded URLs in production.

## 14. Authorization and Role-Based Access Control

### Spatie Permission Setup
- `spatie/laravel-permission` is installed and configured via `config/permission.php`.
- Migrations for permissions and roles are present in `database/migrations/2025_04_24_045144_create_permission_tables.php`.

### Roles Found in Code
- `admin`
- `agent`

### Permission Checks
- `EventController@store` checks `hasAnyRole(['admin', 'agent'])`.
- `EventController@update` checks `hasAnyRole(['admin', 'agent'])` OR owner.
- `EventController@destroy` checks `hasAnyRole(['admin'])` OR owner.

### Inconsistencies
- `User` model does not contain `HasRoles` trait.
- No user role column exists in the schema.
- `AuthController::handleGoogleCallback` returns `role` in redirect data even though it is not stored.

### Admin / Agent / Normal User Capabilities
- Admins are intended to create, update, delete events.
- Agents are intended to create and update events.
- Normal users should be able to view events, like events, and read recommendations.
- Ownership check on update/destroy is based on `created_by` field.

### Risk
- Changing role names will break current authorization checks.
- If `User` is later updated to support roles, the existing `hasAnyRole` usage may become valid.

## 15. Event Management System

### Event Creation
- Performed in `EventController@store`.
- Requires auth and role check.
- Validates event details, location, organizer, images, ticket types, FAQs, and options.
- Creates an `Organizer`, `EventAddress`, `Event`, `TicketType` records, `Faq` records, and associates `EventOption` records.
- Stores images in `storage/app/public/events/images`.
- `created_by` is set to current user ID.

### Event Listing
- `EventController@index` lists events with optional filtering.
- Supported query params: `category_id`, `subcategory_id`, `date_from`, `date_to`, `featured`, `sort`, `per_page`.
- Sort options: `date-asc`, `date-desc`, `price-asc`, `price-desc`.
- Default sort is latest events first.
- Returns manual pagination structure.

### Event Details
- `EventController@show` returns event details with relations.
- Includes `category`, `organizer`, `address`, `subCategories`, `ticketTypes`, `faqs`, `eventOptions`.

### Event Updating
- `EventController@update` allows partial updates.
- Image replacement deletes old files from `public` storage.
- Subcategory sync updates pivot entries.
- Does not update organizer, address, ticket types, FAQs, or event options.

### Event Deletion
- `EventController@destroy` deletes event images and the event record.
- `events` cascade deletes related ticket types, FAQs, and pivot records via foreign keys.
- No soft deletes are configured.

### Business Rules
- `featured` is Boolean and can be set.
- `status` column exists in database and is used by recommendations, but the Event model does not explicitly cast it.
- `created_by` tracks event ownership.

### Future AI Warning
- Do not rename event fields without updating migrations, models, and controllers.
- Do not change relationship methods without updating controllers and potential frontend payload assumptions.
- Do not change image storage paths without checking `storage:link` and public URL behavior.
- Do not change `status` values without updating recommendation filters and migrations.
- Do not change route model binding or route names without checking frontend calls.

## 16. Ticketing System

### Ticket Types
- Table: `ticket_types`
- Model: `TicketType`
- Relationship: belongsTo `Event`
- Created during `EventController@store` for each ticket type in `ticket_types` array.
- Fields:
  - `name`
  - `price`
  - `quantity`
  - `description`
  - `sales_end_date`
  - `is_featured`

### Ticketing Assumptions
- Events have a `total_tickets` aggregate field on `events`.
- Each `TicketType` also has `quantity`.
- The system does not currently enforce inventory consistency between `events.total_tickets` and `ticket_types.quantity`.
- No purchase flow updates ticket quantities automatically.

### Missing Logic
- Payment processing is not found in current codebase, but the `purchases` table/model appears prepared for it.
- There is no checkout endpoint or ticket purchase controller.

## 17. Purchase System

### Purchase Model
- `app/Models/Purchase.php`
- Table: `purchases`
- Fields:
  - `user_id`
  - `event_id`
  - `ticket_type_id`
  - `quantity`
  - `total_price`
  - `status`
  - `payment_method`
  - `transaction_id`

### Status and Scopes
- `pending`, `completed`, `cancelled`, `refunded`
- `scopeCompleted()` filters completed purchases.
- `scopeForUser($userId)` filters by user.
- `scopeForEvent($eventId)` filters by event.

### Relationship Usage
- `Purchase` belongs to `User`, `Event`, and `TicketType`.
- `RecommendationEngine` uses purchases to exclude bought events and score categories.

### Implementation Gaps
- There is no Purchase controller or routes.
- The data structure exists, but purchase creation, payment capture, and order management are not implemented.

## 18. User Interaction System

### Event Likes
- Table: `event_likes`
- Model: `EventLike`
- Unique constraint prevents duplicate likes.
- `EventController@toggleLike` toggles a like for the authenticated user.
- `EventController@checkLiked` returns the current liked state.
- If a like is created, recommendation cache is cleared.

### Event Views
- Table: `event_views`
- Model: `EventView`
- `trackView()` records a view only if there is no view by the same user/event within the last hour.
- `EventController@trackView` calls this when authenticated.
- Views are used by recommendation scoring and the `Jump back in...` bucket.

### Liked Events
- `GET /api/user/liked-events` returns events liked by the current user.
- Loads `category`, `address`, and `ticketTypes`.
- Orders events by latest.
- Note: the route is not protected though the controller expects auth.

## 19. Recommendation Engine Deep Dive

### Files
- `app/Services/RecommendationEngine.php`
- `app/Http/Controllers/Api/RecommendationController.php`

### Purpose
Generates personalized event recommendations for an authenticated user and caches the results.

### Cache Strategy
- Cache key: `recommendations.user.{userId}.{YmdH}`
- TTL: 30 minutes
- Hourly rotation because the key includes the current hour.
- `RecommendationController@clearCache()` deletes the current hourly cache key.

### Eligibility Rules
All recommendation buckets filter events by:
- `events.date >= now()`
- `events.total_tickets > 0`
- `events.status = 'active'`

They also exclude purchased events in most cases.

### Because You Liked
- Uses categories from purchases and likes via `getUserPreferredCategoryIds()`.
- Selects events in those categories.
- Excludes purchased events.
- Orders by event date ascending.
- Returns up to 10 events.
- Response includes formatted price and date.

### Jump Back In
- Uses `event_views` for the current user.
- Joins events to views and groups by event.
- Excludes purchased events.
- Orders by most recent `viewed_at`.
- Returns up to 10 events.

### Made For You
- Uses `getTopCategoriesByInteraction()` to score categories by user interactions.
- Interaction scoring:
  - purchases = 10 points,
  - likes = 5 points,
  - views = 1 point.
- Picks top 3 categories by score.
- Excludes all interacted events from `getUserInteractedEventIds()`.
- Orders by featured first, then by date ascending.
- Returns up to 10 events.

### Helper Methods
- `getUserPreferredCategoryIds()` returns categories from purchased or liked events.
- `getUserPurchasedEventIds()` returns event IDs from purchases.
- `getUserInteractedEventIds()` returns unique event IDs from purchases, views, and likes.
- `getTopCategoriesByInteraction()` uses left joins with purchases, likes, and views to calculate weighted scores per category.

### Performance Notes
- The recommendations engine relies on indexes on `event_views` and `event_likes`.
- Caching reduces repeated SQL computation.
- The queries use joins and grouping; each bucket can be moderately expensive on large datasets.
- N+1 risk is low because selections are aggregated into arrays rather than eager loading full models.

### Future AI Warning
- Do not change scoring weights without reviewing recommendation behavior.
- Do not remove event exclusions for purchased or interacted events.
- Do not remove `status='active'` or date filters without understanding product expectations.
- Preserve cache key format and invalidation on like changes.

## 20. Categories and Subcategories

### Categories
- Table: `categories`
- Seeded by `CategorySeeder` with 14 names.
- Served by `CategoryController@index`.
- Used in event creation and filtering.

### Subcategories
- Table: `sub_categories`
- Seeded by `SubCategorySeeder` with many items.
- Served by `SubCategoryController@index`.
- Related to events via pivot `event_sub_category`.

### Event Filtering
- `events` can be filtered by `category_id` or `subcategory_id`.
- The frontend likely uses categories for broad filtering and subcategories for more specific event discovery.

## 21. Organizers and Event Addresses

### Organizers
- Table: `organizers`
- Model: `Organizer`
- Fields: `name`, `email`.
- Created in `EventController@store` for event creation.
- Events reference `organizer_id`.

### Event Addresses
- Table: `event_addresses`
- Model: `EventAddress`
- Fields: `venue_name`, `address_line1`, `address_line2`, `city`, `state`, `postal_code`, `country`.
- Created in `EventController@store`.
- Events reference `address_id`.

### Usage
- Organizer and address details are loaded in `EventController@show`.
- Event detail frontend views should display venue and organizer information.

## 22. Event Options and Metadata

### EventOptions
- Table: `event_options`
- Pivot: `event_event_option`
- Model: `EventOption`
- Fields: `name`, `is_custom`.
- `EventController@store` creates or retrieves options and attaches them to the event.
- Custom options are created with `is_custom=true`.

### Implementation Notes
- Event options are a flexible metadata layer.
- No endpoint exists to list options separately.
- Event detail response includes `eventOptions`.

## 23. Image Upload and Storage System

### Image Fields
- `main_image`
- `banner_image`

### Validation
- `main_image` and `banner_image` are required on create.
- Allowed mime types: `jpeg`, `png`, `gif`.
- Max size: 5120KB.

### Storage
- Stored on the `public` disk via `request()->file(...)->store('events/images', 'public')`.
- `config/filesystems.php` defines `public` disk root at `storage/app/public` and URL `env('APP_URL').'/storage'`.

### Public Access
- `php artisan storage:link` is required to expose files under `public/storage`.

### Update Behavior
- When updating images, the controller deletes the old file from `Storage::disk('public')`.
- New images are stored under the same `events/images` path.

### Concerns
- No image optimization, resizing, or validation beyond MIME and size.
- Placeholder image text is used in seeders.
- If `storage:link` is not created, image URLs will not resolve.
- If `APP_URL` or `FILESYSTEM_DISK` is misconfigured, image URLs can break.

## 24. Middleware and Request Pipeline

### Authenticate Middleware
File: `app/Http/Middleware/Authenticate.php`
- Extends Laravel auth middleware.
- For `api/*` requests, returns `null` to force 401 responses.
- For web requests, redirects to `FRONTEND_URL/login` if unauthenticated.
- Ensures API routes do not redirect users to pages.

### Cors Middleware
File: `app/Http/Middleware/Cors.php`
- Handles preflight `OPTIONS` by returning 200.
- Reads `Origin` header.
- Allows hardcoded localhost/127.0.0.1 origins on ports 3000, 5173, 5174, 5175.
- Also checks `CORS_ALLOWED_ORIGINS` env variable.
- Sets `Access-Control-Allow-Methods`, `Access-Control-Allow-Headers`, `Access-Control-Allow-Credentials`, `Access-Control-Max-Age`, and `Vary: Origin`.
- This is a custom implementation and duplicates `config/cors.php` logic.

### RedirectIfAuthenticated Middleware
File: `app/Http/Middleware/RedirectIfAuthenticated.php`
- Standard Laravel middleware to send already-authenticated users away from guest pages.
- No custom behavior beyond default.

### Kernel and Middleware Registration
File: `app/Http/Kernel.php`
- Global middleware includes `Cors`, `HandleCors`, `ValidatePostSize`, `TrimStrings`, `ConvertEmptyStringsToNull`.
- `api` group includes throttling and binding substitution.
- `web` group includes cookie encryption, session, CSRF, and substitutions.
- `cors` alias points to custom `App\Http\Middleware\Cors`.

## 25. CORS, Cookies, Sessions, and Frontend Integration

### Frontend Requirements
- Backend API base URL is assumed `http://localhost:8000`.
- Frontend base URLs are assumed `http://localhost:3000` or `http://localhost:5173-5175`.
- Axios or similar must use `withCredentials: true`.
- Frontend must call `GET /sanctum/csrf-cookie` before login/register.

### Session / Cookie Configuration
- `config/session.php` uses `SESSION_DRIVER=database` by default.
- `secure` defaults to `false` unless `SESSION_SECURE_COOKIE=true`.
- `same_site` defaults to `lax`.
- `SESSION_DOMAIN` should be configured for cross-domain frontend integration.
- `config/sanctum.php` builds `stateful` domains from `SANCTUM_STATEFUL_DOMAINS` and local defaults.

### Common Error Sources
- Missing `withCredentials` on frontend.
- CORS origin not matching allowed origins.
- `SANCTUM_STATEFUL_DOMAINS` missing the frontend host.
- `SESSION_DOMAIN` misconfigured for production domain.
- `SESSION_SECURE_COOKIE` false on HTTPS.

### Sample Frontend Flow
This is inferred from backend behavior, not actual frontend code:
```js
const api = axios.create({
  baseURL: 'http://localhost:8000',
  withCredentials: true,
  headers: {
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest'
  }
});

await api.get('/sanctum/csrf-cookie');
await api.post('/api/login', { email, password });
await api.get('/api/user');
```

## 26. Validation and Error Handling

### login validation
- `email`: required, email
- `password`: required
- Errors return 422 for invalid input.

### register validation
- `name`: required, string, max 255
- `email`: required, string, email, max 255, unique
- `password`: required, string, min 8, confirmed
- Returns 422 on validation errors.

### event creation validation
- strict rules on title, description, category, organizer, location, date, price, tickets, images.
- `ticket_types.*.sales_end_date` must be before or equal to event `date`.
- `main_image` and `banner_image` required images.
- If validation fails, Laravel returns 422 errors.

### event update validation
- partial update via `sometimes|required`.
- image replacements require valid image files.
- `sub_categories` must exist, max 5.

### image validation
- MIME: jpeg, png, gif
- Max: 5120 KB
- No explicit dimension checks.

### ticket type validation
- each ticket type requires `name`, `price`, `quantity`.
- optional description, sales end date, featured flag.

### FAQ validation
- `faqs` is optional.
- `faqs.*.question` and `faqs.*.answer` are nullable strings.

### category/subcategory validation
- `category_id` must exist in `categories`.
- `sub_categories.*` must exist in `sub_categories`.

### address validation
- `location.*` fields are required except `address_line2`.

### organizer validation
- `organizer.name` is required.
- `organizer.email` is optional.

### Error codes in controllers
- 200 success
- 201 created
- 401 unauthenticated
- 403 unauthorized
- 500 server error
- 422 validation error (Laravel default)

### Transactions
- `EventController@store`, `update`, and `destroy` use `DB::transaction()`.
- On exception, the transaction rolls back.

### Error format
- Most controller errors return JSON with `error` and `message`.

## 27. Security Analysis

### Implemented protections
- CSRF protection through Sanctum and `sanctum/csrf-cookie` route.
- Secure cookie settings exist in `config/session.php`.
- Password hashing with `Hash::make()`.
- Input validation in controllers.
- Custom CORS whitelist with credential support.
- Throttling at API group and explicit track-view route.
- Eloquent ORM used for query building.
- `EventLike` prevents duplicate likes with unique constraint.
- `EventView` prevents duplicate view records within one hour.

### Partially implemented protections
- Spatie RBAC package is installed, but user role implementation is incomplete.
- `App\Models\User` may not properly cast email verification and password.
- `EventController` imports AntiXSS but does not sanitize input.
- `config/cors.php` defines allowed origins, but custom middleware may override or duplicate behavior.

### Missing / recommended protections
- Production HTTPS enforcement and secure cookie enforcement must be verified.
- `SESSION_DOMAIN` should be set in production.
- `GOOGLE_REDIRECT_URI` should not be hardcoded.
- Sensitive data should not be returned in redirect query strings.
- Payment and purchase handling is not secured because it is not implemented.

### OAuth risk
- Local SSL bypass with `verify => false` is acceptable only for development.
- The backend should not use this in production.

## 28. Performance and Indexing

### Database indexes
- `event_views`:
  - `(user_id, event_id, viewed_at)`
  - `(event_id, viewed_at)`
  - `(user_id)`
- `event_likes`:
  - unique `(user_id, event_id)`
  - `(user_id)`
  - `(event_id)`
- `purchases`:
  - `(user_id, event_id)`
  - `(user_id, created_at)`
  - `(status)`
  - `(event_id)`
- `events.status` index in the status migration.

### Query optimization
- Event listing uses eager loading to avoid N+1.
- Recommendation engine uses joins and group by.
- `EventController@index` still calls `Event::count()` and `$query->count()` for logs, which may be expensive.

### Pagination
- Default page size 10.
- Max page size enforced at 100.
- Manual pagination wrapper is returned instead of Laravel resource pagination.

### Scaling concerns
- Recommendation queries on `event_views` and `event_likes` could grow heavy.
- Caching helps, but queries still join tables.
- Image storage on local disk may need migration to S3 for production.

## 29. Factories, Seeders, and Test Data

### Factories
- `UserFactory.php` generates authenticated users.
- `EventFactory.php` is empty.
- `CategoryFactory.php`, `SubCategoryFactory.php`, `OrganizerFactory.php`, `EventAddressFactory.php`, `TicketTypeFactory.php`, `FaqFactory.php`, `EventOptionFactory.php` exist but are not inspected here.

### Seeders
- `DatabaseSeeder.php` creates a single test user `test@example.com`, then calls `CategorySeeder`, `SubCategorySeeder`, and `EventSeeder`.
- `CategorySeeder.php` inserts 14 category names if they do not already exist.
- `SubCategorySeeder.php` inserts dozens of subcategories if not present.
- `EventSeeder.php` creates organizers, addresses, categories, subcategories, events, tickets, and FAQs.

### Seed Data
- Default user: `test@example.com`.
- Categories include Music, Sports, Education, Business, Food & Drink, Arts & Culture, Technology, Health & Wellness, Family & Kids, Charity & Causes, Hobbies & Special Interest, Travel & Outdoor, Community & Culture.
- Seeded events include `Summer Music Festival`, `Comedy Night`, `Art Exhibition Opening`, `Basketball Championship`, `Food & Wine Festival`, and `Tech Conference 2024`.
- Ticket types and FAQs are seeded for select events.
- Event seeder creates placeholder image files in `storage/app/public/events/images`.

## 30. Testing Setup

### Framework
- Uses Pest via `pestphp/pest` and `pestphp/pest-plugin-laravel`.
- `tests/Pest.php` and `tests/TestCase.php` exist.
- There are only example tests in `tests/Feature/ExampleTest.php` and `tests/Unit/ExampleTest.php`.

### Coverage
- No real backend tests are present for controllers, auth, event flows, or recommendations.
- Suggested future tests:
  - auth login/register/logout.
  - event CRUD authorization.
  - event creation validation.
  - image upload.
  - like toggle.
  - view tracking duplicate prevention.
  - recommendation scoring cachinG.
  - purchase model scopes.
  - CORS/Sanctum auth flow.

### How to run tests
- `php artisan test`
- Or `composer test`.

## 31. API Response Standards

### Response types
- Successful list/detail endpoints return JSON with `data`.
- Event list endpoint returns manual `data`, `meta`, and `links`.
- Some endpoints return simple JSON with `message` or status booleans.
- There is no consistent resource wrapper or API resource classes.

### Error standards
- Controllers commonly return `{ error: '...', message: '...' }`.
- Validation errors use Laravel default 422 JSON.
- Some error responses return status 500 for internal errors.

### Inconsistencies
- `EventController@show` returns `data` object, while `toggleLike` returns raw fields without `data` wrapper.
- `RecommendationController@index` returns `data` and `meta`, but not `links`.

## 32. Frontend Contract

### API base URL
- Backend: `http://localhost:8000`
- Frontend: `http://localhost:3000` or `http://localhost:5173`

### CSRF requirement
- Must request `GET /sanctum/csrf-cookie` before login/register.
- Use `withCredentials: true`.

### Auth routes
- `POST /api/login`
- `POST /api/register`
- `GET /api/user`
- `POST /api/logout`
- `GET /api/auth/google`
- `GET /api/auth/google/callback`

### Event listing query params
- `category_id`
- `subcategory_id`
- `date_from`
- `date_to`
- `featured`
- `sort` (`date-asc`, `date-desc`, `price-asc`, `price-desc`)
- `per_page`

### Event detail shape
- Includes event fields plus relations: category, organizer, address, subCategories, ticketTypes, faqs, eventOptions.

### Create event payload
- Must include event metadata, organizer nested object, location nested object, files for `main_image` and `banner_image`, ticket_types array, optional faqs array, optional event_options array.
- Must be multipart/form-data for file uploads.

### Recommendation shape
- `data` contains `because_you_liked`, `jump_back_in`, and `made_for_you`.
- Each contains `label`, `count`, and `events` list.

### Liked-events shape
- `{ data: [ event, ... ] }`
- Event objects include category, address, ticketTypes.

### Error handling
- Expect 401 for unauthenticated, 403 for unauthorized, 422 for validation, 500 for server error.
- For auth errors, `AuthController` returns validation exception or 401 from middleware.

### Role restrictions
- Only admins/agents can create/update events.
- Only admins or owners can delete events.
- Ordinary users can like, view, and fetch recommendations.

## 33. Known Limitations and Missing Features

- Payment processing is not implemented in current codebase.
- Purchase controller/routes are missing.
- User role enforcement is incomplete due to missing User role integration.
- `users` table lacks a `role` field.
- `EventFactory` is empty.
- `getLikedEvents` route lacks auth middleware.
- Google OAuth redirect URL is hardcoded to localhost.
- `DB::table()` is used in a migration without the required `DB` import.
- `EventController` imports `AntiXSS` but does not sanitize input.
- Frontend integration assumptions are present but no frontend code in repo.
- `config/cors.php` and custom `Cors` middleware may duplicate configuration.
- Recommendations are rules-based and cached hourly.

## 34. Fragile Areas and Future AI Warnings

- `User` role checks are fragile because the `User` model does not implement Spatie roles.
- `AuthController@handleGoogleCallback` returns a `role` field that is not stored.
- `EventController@store/update/destroy` use `hasAnyRole()` which may fail.
- `GET /api/user/liked-events` should be protected by auth middleware.
- `2025_11_27_050041_add_status_to_events_table.php` may fail migration without `DB` import.
- Do not change event field names or pivot table names lightly.
- Do not change the recommendation filters or cache keys without assessing impact.
- Do not refactor `EventController` or auth flow unless requested.
- Do not alter `config/session.php`/`config/sanctum.php` casually; these control cookies and SPA auth.
- Do not change Google OAuth redirect hardcoded hosts without coordinating frontend.
- Do not change image storage paths without verifying `storage:link` and public URL assumptions.

## 35. Safe Development Rules for This Backend

1. Do not rename database fields without updating migrations, models, controllers, and frontend contract.
2. Do not change route paths without checking `routes/api.php` and frontend usage.
3. Do not change Sanctum/CORS/session settings casually.
4. Do not remove CSRF requirements for browser clients.
5. Do not change role strings without updating all authorization checks.
6. Do not alter recommendation scoring or exclusions without documenting product impact.
7. Do not rewrite `EventController` unless a refactor is explicitly requested.
8. Do not add payment logic without designing purchase flow and routes.
9. Do not change image storage path without checking `public/storage` URL behavior.
10. Keep changes small and file-by-file.
11. Explain existing flow before suggesting code changes.
12. Ask for current files if this document may be outdated.

## 36. Future Development Priorities

### Stability
- Add tests for auth and event CRUD.
- Cover recommendation logic.
- Fix auth role integration.
- Guard liked-events route.

### Security
- Harden CORS and Sanctum in production.
- Remove local SSL bypass for Google OAuth in production.
- Validate role-based access and user traits.

### Event / Ticketing
- Build purchase endpoints.
- Integrate payment gateway.
- Sync ticket inventory with purchases.
- Add ticket confirmation and order emails.

### Recommendations
- Improve cache invalidation.
- Add fallback recommendations for new users.
- Tune scoring and category weighting.

### Documentation
- Add OpenAPI/Swagger or API docs.
- Document frontend contract thoroughly.

### Deployment
- Confirm `storage:link`.
- Configure queue workers if needed.
- Configure cache and session drivers for production.
- Use HTTPS and secure cookies.

## 37. Context Request Guide for Future ChatGPT Conversations

### If the issue is about authentication
Ask for:
- `routes/api.php`
- `app/Http/Controllers/Api/AuthController.php`
- `config/sanctum.php`
- `config/cors.php`
- `config/session.php`
- frontend axios config or network request details

### If the issue is about event creation
Ask for:
- `app/Http/Controllers/Api/EventController.php` store method
- `app/Models/Event.php`
- event-related migrations
- frontend request payload
- validation error output
- `config/filesystems.php` if images are involved

### If the issue is about recurring events
Ask for:
- whether recurrence exists in current backend
- event migration file
- `EventController@store` and `update`
- frontend payload shape for recurrence
- intended recurrence data model

### If the issue is about recommendations
Ask for:
- `app/Services/RecommendationEngine.php`
- `app/Http/Controllers/Api/RecommendationController.php`
- `app/Models/EventLike.php`
- `app/Models/EventView.php`
- `app/Models/Purchase.php`
- relevant migrations
- sample user interaction data

### If the issue is about roles/permissions
Ask for:
- `app/Models/User.php`
- `config/permission.php`
- seeders or role setup
- `app/Http/Controllers/Api/EventController.php` authorization checks
- middleware/policies if present

### If the issue is about CORS/Sanctum
Ask for:
- `.env` variable names/values with secrets removed
- `config/cors.php`
- `config/sanctum.php`
- `config/session.php`
- frontend API client config
- actual request/response headers

### If the issue is about database
Ask for:
- migration file
- model file
- controller/service using the table
- exact SQL or Laravel error message

### If the issue is about API response shape
Ask for:
- controller method
- frontend API call
- actual response JSON
- expected response JSON

## 38. Instructions for Future AI Assistants

You are helping continue the backend of this Laravel event/ticketing platform.

Before answering:
- Read this document first.
- Treat it as context, not proof that code has not changed.
- Ask for current files when actual code changes are needed.
- Identify the exact backend area involved.
- Explain the current flow before suggesting changes.
- Make minimal safe changes unless a larger refactor is requested.
- Do not rewrite unrelated files.
- Do not rename routes, controllers, models, methods, table fields, role strings, status values, or API response fields unless necessary.
- Preserve Sanctum/CORS/session behavior.
- Preserve role-based access rules.
- Preserve event creation data shape unless intentionally changing frontend/backend contract.
- Preserve recommendation exclusions and cache behavior unless intentionally redesigning recommendations.
- When giving code, provide file-by-file changes.
- When debugging, explain the root cause first.
- If unsure, say what file is needed instead of guessing.
- Never expose secrets.
- Never suggest uploading `.env` with real secrets.
- For backend/frontend integration problems, always consider CORS, cookies, CSRF, domain, and route mismatch.

## 39. Final Backend Summary

This backend is a Laravel 12 event management and ticketing API. It includes user auth, Google OAuth, event CRUD, categories, subcategories, organizers, venues, ticket types, FAQs, event options, likes, views, and a recommendation engine. The most important models are `Event`, `EventLike`, `EventView`, `Purchase`, `TicketType`, `Category`, and `SubCategory`. The most important controllers are `AuthController`, `EventController`, and `RecommendationController`.

The most important routes are:
- `POST /api/login`
- `POST /api/register`
- `GET /api/user`
- `GET /api/events`
- `GET /api/events/{event}`
- `POST /api/events`
- `POST /api/events/{event}/toggle-like`
- `GET /api/recommendations`

Fragile areas:
- Role checks with Spatie and missing User role implementation.
- Google OAuth redirect and SSL bypass.
- `getLikedEvents` missing auth guard.
- Image upload and storage path expectations.
- Recommendation cache invalidation.
- Purchase system scaffold without endpoints.

Future AI must remember:
- Do not change authorization or auth/session behavior lightly.
- Do not rename fields without updating API contracts.
- Do not assume purchases are implemented; they are only modeled.
- Ask for relevant controller, model, and config files before modifying.
