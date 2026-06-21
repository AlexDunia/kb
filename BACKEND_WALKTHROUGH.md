# 🎯 Kakabackend - Complete Backend Walkthrough

## Project Overview

**Project Type:** Event Management & Ticketing Platform API

**Purpose:** A Laravel-based REST API for managing events, tickets, categories, and user interactions with real-time personalized recommendations.

**Tech Stack:**
- PHP 8.2+ / Laravel 12.0
- Sanctum (API Authentication)
- Socialite (Google OAuth)
- Spatie Permission (Role-Based Access Control)
- Pest (Testing Framework)
- Vite + TailwindCSS (Frontend Build)

---

## 📊 Database Schema - Complete Breakdown

### Core Entity Tables

| Table | Purpose | Key Fields | Relationships |
|-------|---------|-----------|-----------------|
| **users** | User accounts & authentication | id, name, email, password, google_id (nullable), avatar (nullable), timestamps | Central hub for all user interactions |
| **events** | Event listings | id, title, description, category_id, organizer_id, address_id, date, price, total_tickets, duration, featured, main_image, banner_image, created_by, status, timestamps | Core entity with 8 relationships |
| **categories** | Event categories | id, name, timestamps | Has many events (1:M) |
| **sub_categories** | Event subcategories | id, name, timestamps | Many-to-many with events |
| **organizers** | Event organizers | id, name, email, timestamps | Has many events (1:M) |
| **event_addresses** | Event venue information | id, venue_name, address_line1, address_line2, city, state, postal_code, country, timestamps | One-to-many with events |
| **ticket_types** | Ticket options per event | id, event_id, name, price, quantity, description, sales_end_date, is_featured, timestamps | Belongs to event (M:1) |
| **faqs** | Event FAQs | id, event_id, question, answer, timestamps | Belongs to event (M:1) |
| **event_options** | Event metadata options | id, name, is_custom, timestamps | Many-to-many with events |

### User Interaction Tables

| Table | Purpose | Key Indexes |
|-------|---------|------------|
| **event_likes** | User favorite events | user_id, event_id (unique constraint), prevents duplicate likes |
| **event_views** | Event view tracking | user_id, event_id, viewed_at, ip_address with 3 indexes for query optimization |
| **purchases** | Ticket purchases | user_id, event_id, ticket_type_id, status (enum: pending, completed, cancelled, refunded) with 4 performance indexes |

### Pivot Tables

- **event_sub_category** - Maps events to multiple subcategories
- **event_event_option** - Maps events to event options

### Framework Tables

- **password_reset_tokens** - Password recovery tokens
- **sessions** - HTTP session storage
- **cache** - Cache storage
- **jobs** - Queue job storage
- **personal_access_tokens** - Sanctum API tokens
- **permissions, roles, model_has_permissions, model_has_roles, role_has_permissions** - Spatie permission system

**Schema Design Notes:**
- Event status field (draft, active, cancelled, completed) ensures only active events are recommended
- Comprehensive indexing on event_views for fast view tracking queries
- Purchase table tracks transaction status and payment metadata
- Event likes prevent duplicates via unique constraint

---

## 🗂️ Models - Relationships & Attributes

### User Model
- **Fillable:** name, email, password
- **Hidden:** password, remember_token
- **Casts:** email_verified_at (datetime), password (hashed)
- **Extensions:** Google OAuth support (google_id, avatar fields)
- **Relationships:** Implicit - many purchases, many event likes, many event views

### Event Model
- **Fillable:** title, description, category_id, organizer_id, address_id, date, price, total_tickets, duration, featured, main_image, banner_image, created_by
- **Casts:** date (datetime), price (decimal:2), featured (boolean)
- **Relationships:**
  - `category()` - BelongsTo Category
  - `organizer()` - BelongsTo Organizer
  - `address()` - BelongsTo EventAddress
  - `createdBy()` - BelongsTo User
  - `ticketTypes()` - HasMany TicketType
  - `faqs()` - HasMany Faq
  - `subCategories()` - BelongsToMany SubCategory

### Category Model
- **Fillable:** name
- **Relationship:** `events()` - HasMany Event

### SubCategory Model
- **Fillable:** name
- **Relationship:** `events()` - BelongsToMany Event

### Organizer Model
- **Fillable:** name, email
- **Relationship:** `events()` - HasMany Event

### EventAddress Model
- **Fillable:** venue_name, address_line1, address_line2, city, state, postal_code, country
- **Relationship:** `events()` - HasMany Event

### TicketType Model
- **Fillable:** event_id, name, price, quantity, description, sales_end_date, is_featured
- **Casts:** price (decimal:2), quantity (integer), sales_end_date (date), is_featured (boolean)
- **Relationship:** `event()` - BelongsTo Event

### Faq Model
- **Fillable:** event_id, question, answer
- **Relationship:** `event()` - BelongsTo Event

### EventOption Model
- **Fillable:** name, is_custom
- **Casts:** is_custom (boolean)
- **Relationship:** `events()` - BelongsToMany Event

### EventLike Model
- **Table:** event_likes
- **Fillable:** user_id, event_id
- **Methods:**
  - `toggleLike(userId, eventId)` - Creates/deletes like
  - `isLiked(userId, eventId)` - Boolean check
- **Relationships:** user, event
- **Note:** No UPDATED_AT timestamp

### EventView Model
- **Table:** event_views
- **Fillable:** user_id, event_id, viewed_at, ip_address
- **Method:** `trackView(userId, eventId, ip)` - Avoids duplicate views within 1 hour
- **Relationships:** user, event

### Purchase Model
- **Fillable:** user_id, event_id, ticket_type_id, quantity, total_price, status, payment_method, transaction_id
- **Casts:** total_price (decimal:2), quantity (integer), dates (datetime)
- **Status Constants:** PENDING, COMPLETED, CANCELLED, REFUNDED
- **Scopes:** `completed()`, `forUser(userId)`
- **Relationships:** user, event, ticketType

---

## 📡 Routes & API Endpoints

### Authentication Routes
```
POST   /api/login                    - User login
POST   /api/register                 - User registration
POST   /api/logout                   - User logout (auth:sanctum)
GET    /api/user                     - Get current user (auth:sanctum)
GET    /sanctum/csrf-cookie          - Get CSRF cookie for frontend
GET    /api/auth/google              - Google OAuth redirect
GET    /api/auth/google/callback     - Google OAuth callback handler
```

### Event Routes
```
GET    /api/events                   - List events with filtering & pagination
GET    /api/events/{event}           - Get single event with all relations
POST   /api/events                   - Create event (auth:sanctum, admin/agent role)
PUT    /api/events/{event}           - Update event (auth:sanctum, owner/admin)
DELETE /api/events/{event}           - Delete event (auth:sanctum, owner/admin)
POST   /api/events/{event}/track-view - Track event view (throttled: 60/min)
POST   /api/events/{event}/toggle-like - Like/unlike event (auth:sanctum)
GET    /api/events/{event}/check-liked - Check if liked (no auth required)
GET    /api/user/liked-events        - Get user's liked events
```

### Category Routes
```
GET    /api/categories               - List all categories
GET    /api/subcategories            - List all subcategories
```

### Recommendation Routes (auth:sanctum)
```
GET    /api/recommendations          - Get personalized recommendations
POST   /api/recommendations/clear-cache - Clear recommendation cache
```

### Test Routes
```
GET    /api/ping                     - Health check endpoint
GET    /api/cors-test                - CORS testing endpoint
```

---

## 🎮 Controllers - Business Logic

### AuthController

**Methods:**
- `login(Request)` - Email/password login with session regeneration
- `register(Request)` - New user registration with validation (min 8 char password)
- `logout(Request)` - Session invalidation and CSRF token regeneration
- `user(Request)` - Return current authenticated user
- `redirectToGoogle(Request)` - Initiates Google OAuth flow with SSL bypass for local dev
- `handleGoogleCallback(Request)` - Processes Google callback, creates/updates user, returns userData

**Validation:**
- Login: required email, required password
- Register: required name, unique email, password (min 8, confirmed)

**Google OAuth Integration:**
- Creates/updates user from Google profile (id, name, email, avatar)
- Sets email_verified_at automatically
- Generates random password for OAuth users
- Redirects to frontend with user data in query params

### EventController

**Methods:**

1. **index(Request)** - List events with advanced filtering
   - Filters: category_id, subcategory_id, date_from, date_to, featured
   - Sorting: date-asc, date-desc, price-asc, price-desc (default: latest)
   - Pagination: max 100 items per page
   - Eager loads: category, organizer, address, subCategories

2. **show(Event)** - Get single event with all relations
   - Loads: category, organizer, address, subCategories, ticketTypes, faqs, eventOptions

3. **store(Request)** - Create event (requires admin/agent role)
   - Comprehensive validation for event, location, organizer, images, ticket types, FAQs
   - Stores images to public disk (events/images path)
   - Creates related organizer and address
   - Attaches subcategories and event options
   - Transaction-based creation for consistency
   - Returns event ID with success message

4. **update(Request, Event)** - Update event (owner or admin)
   - Partial updates supported
   - Handles image replacement with old file deletion
   - Syncs subcategories
   - Returns updated event with relations

5. **destroy(Event)** - Delete event (owner or admin only)
   - Deletes associated images from storage
   - Cascading delete removes all relations
   - Transaction-based for safety

6. **trackView(Event)** - Record event view
   - Throttled to 60 requests/min
   - Prevents duplicate views within 1 hour
   - Captures IP address
   - Silently fails if error (returns success: false)

7. **toggleLike(Event)** - Like/unlike event (requires auth)
   - Uses EventLike::toggleLike() helper
   - Clears recommendation cache on like
   - Returns like status and total likes count

8. **checkLiked(Event)** - Check if user liked event (no auth required)
   - Returns boolean and likes count

9. **getLikedEvents(Request)** - Get authenticated user's liked events
   - Loads: category, address, ticketTypes
   - Orders by latest first

### CategoryController

**Methods:**
- `index(Request)` - Returns all categories as JSON array

### SubCategoryController

**Methods:**
- `index(Request)` - Returns all subcategories as JSON array

### RecommendationController

**Methods:**
- `index()` - Returns personalized recommendations (requires auth)
  - Calls RecommendationEngine service
  - Returns 3 recommendation types with metadata

- `clearCache()` - Manually clear user's recommendation cache (requires auth)

---

## 🔧 Services - Business Logic Layer

### RecommendationEngine

**Purpose:** Personalized event recommendations with intelligent caching

**Cache Settings:**
- TTL: 30 minutes
- Cache key format: `recommendations.user.{userId}.{YmdH}` (hourly rotation)

**Recommendation Categories:**

1. **"Because you liked..."** `getBecauseYouLikedRecommendations()`
   - Shows events in categories user has interacted with (purchases + likes)
   - Excludes already purchased events
   - Excludes past events
   - Limited to events with available tickets
   - Only includes 'active' status events
   - Ordered by earliest date first

2. **"Jump back in..."** `getJumpBackInRecommendations()`
   - Events user previously viewed but hasn't purchased
   - Ordered by most recently viewed
   - Same exclusions as above

3. **"Made for you..."** `getMadeForYouRecommendations()`
   - Top 3 categories by interaction score
   - Interaction scoring: purchases (10pts), likes (5pts), views (1pt)
   - Prioritizes featured events
   - Excludes all previously interacted events

**Helper Methods:**
- `getUserPreferredCategoryIds()` - Categories from purchases + likes
- `getUserPurchasedEventIds()` - All bought events
- `getUserInteractedEventIds()` - Combined purchased, viewed, liked
- `getTopCategoriesByInteraction()` - Weighted category scoring

**Performance:**
- Complex left joins with conditional aggregation
- 3 indexes on event_views table for fast lookups
- Caching prevents recalculation within hour window

---

## 🔐 Security & Middleware

### Middleware Stack

**Authenticate Middleware**
- Redirects unauthenticated API requests to frontend login URL
- Returns 401 for API requests without auth

**Cors Middleware**
- Custom CORS implementation handling pre-flight OPTIONS requests
- Whitelist approach for allowed origins
- Supports credentials (sets specific origin, not wildcard)
- Configured for localhost:3000, 5173-5175 (dev), and production domain

**RedirectIfAuthenticated Middleware**
- Standard Laravel middleware for redirecting authenticated users away from login/register

---

## ⚙️ Configuration - Environment Setup

### CORS Configuration

- **Paths:** api/*, sanctum/csrf-cookie, events/*
- **Allowed Methods:** All (GET, POST, PUT, DELETE, etc.)
- **Allowed Origins (Local):**
  - localhost:3000, 5173-5175
  - 127.0.0.1:3000, 5173-5175
- **Headers:** Allow all (*)
- **Max Age:** 86400 seconds (1 day)

### Sanctum Configuration

- **Stateful Domains:** localhost (with port variations), 127.0.0.1, ::1
- **Guards:** web (session-based)
- Supports both session cookies and bearer tokens

### Auth Configuration

- **Default Guard:** web (session)
- **User Provider:** Eloquent (App\Models\User)
- **Password Reset Expiry:** 60 minutes
- **Password Reset Throttle:** 60 seconds between attempts

### Permission Configuration

- **Permission Model:** Spatie\Permission\Models\Permission
- **Role Model:** Spatie\Permission\Models\Role
- Standard Spatie permission tables: roles, permissions, model_has_permissions, etc.
- Role-based access control (admin, agent roles used in EventController)

### Environment Variables

- `FRONTEND_URL` - Frontend application URL
- `CORS_ALLOWED_ORIGINS` - Comma-separated allowed origins
- `SANCTUM_STATEFUL_DOMAINS` - Domains receiving session cookies
- `SESSION_DOMAIN` - Cookie domain (empty for local dev)
- `SESSION_SECURE_COOKIE` - HTTPS enforcement (true for production)
- `APP_ENV` - environment (local/production)
- `APP_DEBUG` - Debug mode flag

---

## 🌱 Factories & Seeders - Test Data

### Factories

- **UserFactory** - Generates test users
- **EventFactory** - Empty factory (not yet implemented)
- **CategoryFactory** - Categories
- **SubCategoryFactory** - Subcategories
- **OrganizerFactory** - Event organizers
- **EventAddressFactory** - Event addresses
- **TicketTypeFactory** - Ticket types
- **FaqFactory** - FAQs
- **EventOptionFactory** - Event options

### Seeders

**DatabaseSeeder**
```
1. Creates test user (test@example.com)
2. Calls CategorySeeder
3. Calls SubCategorySeeder
4. Calls EventSeeder
```

**CategorySeeder**
Creates 14 main categories:
- Events, Music, Sports, Education, Business, Food & Drink
- Arts & Culture, Technology, Health & Wellness, Family & Kids
- Charity & Causes, Hobbies & Special Interest, Travel & Outdoor
- Community & Culture

**EventSeeder**
- Creates organizers (Global Events Co., Comedy Central, Arts Council, NBA, Culinary Arts Foundation, TechHub)
- Creates address records
- Creates sample events with related data

**SubCategorySeeder**
- Creates subcategories for event classification

---

## 📦 Dependencies

### Production Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| **laravel/framework** | ^12.0 | Core framework |
| **laravel/sanctum** | ^4.1 | API authentication & CSRF |
| **laravel/socialite** | ^5.23 | Social OAuth (Google) |
| **laravel/tinker** | ^2.10.1 | Interactive REPL |
| **spatie/laravel-permission** | ^6.17 | Role-based access control |
| **voku/anti-xss** | ^4.1 | XSS prevention |

### Development Dependencies

| Package | Purpose |
|---------|---------|
| **fakerphp/faker** | Generate fake data |
| **laravel/pail** | Log viewer |
| **laravel/pint** | Code formatting |
| **laravel/sail** | Docker environment |
| **mockery/mockery** | Mocking library |
| **nunomaduro/collision** | Error display |
| **pestphp/pest** | Testing framework |
| **pestphp/pest-plugin-laravel** | Laravel testing utilities |

### Frontend Dependencies

| Package | Purpose |
|---------|---------|
| **vite** | Build tool |
| **laravel-vite-plugin** | Vite integration |
| **tailwindcss** | Utility-first CSS |
| **@tailwindcss/vite** | Tailwind Vite plugin |
| **axios** | HTTP client |
| **concurrently** | Run multiple commands |

---

## 🔑 Authentication - Complete Flow

### Session-Based Authentication (Primary)
```
1. Frontend requests /sanctum/csrf-cookie
2. Backend sets CSRF token cookie
3. Frontend includes CSRF token in login request
4. Backend validates credentials & creates session
5. Backend sets session cookie (secure, httpOnly)
6. Frontend includes cookies in subsequent requests
```

### Google OAuth Authentication (Secondary)
```
1. Frontend redirects to /api/auth/google
2. Backend initiates Google OAuth redirect
3. User logs in with Google
4. Google redirects to /api/auth/google/callback
5. Backend exchanges code for user profile
6. Backend creates/updates user (google_id, avatar)
7. Backend sets email_verified_at
8. Backend creates session
9. Backend redirects to frontend with userData query params
10. Frontend handles oauth/google/success route
```

### User Model Enhancements
- **google_id** - Google OAuth identifier (nullable)
- **avatar** - User profile picture URL (nullable)
- **role** - User role field (used for admin/agent authorization)

### Authorization Strategy
- **Event Creation:** admin or agent roles required
- **Event Update:** admin role or event creator
- **Event Deletion:** admin role only
- **Like/Unlike:** Any authenticated user
- **Recommendations:** Any authenticated user
- **View Tracking:** Optional authentication

### Sanctum Configuration
- Stateful cookie-based authentication for web clients
- Bearer token support for mobile/external clients
- 30-minute idle timeout (configurable)
- CSRF protection enabled

---

## 🌐 Frontend Integration

### Frontend URL Configuration

**Local Development:**
- Backend: http://localhost:8000/api
- Frontend: http://localhost:3000 (or 5173-5175)

**Production:**
- Backend: https://api.yourdomain.com
- Frontend: https://yourdomain.com

### Authentication Flow Example

```javascript
// 1. Configure axios with credentials
const api = axios.create({
  baseURL: 'http://localhost:8000',
  withCredentials: true,
  headers: {
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest'
  }
});

// 2. Get CSRF cookie
await api.get('/sanctum/csrf-cookie');

// 3. Login
const response = await api.post('/api/login', {
  email,
  password
});

// 4. Subsequent requests include cookies automatically
await api.get('/api/user');
```

### Response Format

All endpoints return JSON:
```json
{
  "data": {...},
  "meta": {...},
  "links": {...}
}
```

Errors:
```json
{
  "error": "Error name",
  "message": "Detailed message"
}
```

---

## ✨ Special Features - Advanced Functionality

### A. Recommendation Engine (Complex Algorithm)
- **3-tier recommendation system** with weighted scoring
- **Caching strategy** (30-min TTL, hourly rotation)
- **Interaction scoring:** purchases (10x), likes (5x), views (1x)
- **Smart exclusions:** past events, already-purchased, no tickets
- **Featured event prioritization** in "Made for you" section
- Prevents data leakage via status filtering ('active' only)

### B. Event Interaction Tracking
- **View Tracking:** Rate-limited (60/min), no duplicates within 1 hour, IP capture
- **Like System:** Toggle with unique constraint, clears recommendation cache on interaction
- **Smart Deduplication:** EventView::trackView() prevents duplicate entries

### C. Image Management
- **Dual Images:** main_image + banner_image per event
- **Storage:** Public disk (events/images path)
- **Validation:** JPEG, PNG, GIF max 5MB each
- **Cleanup:** Old images deleted when updated

### D. Ticket Type Management
- **Multiple ticket types per event** with different prices/quantities
- **Featured tickets** for highlighting
- **Sales end dates** for limited-time offers
- **Inventory tracking** via quantity field

### E. FAQ System
- Event-specific FAQs
- Question + answer storage
- Displayed on event detail page

### F. Event Options/Attributes
- Flexible event metadata via many-to-many relationship
- Custom options support (is_custom flag)
- Allows future extension without schema changes

### G. Purchase Tracking
- **4-state system:** pending, completed, cancelled, refunded
- **Payment metadata:** method, transaction_id (unique)
- **Comprehensive indexing** for user/event/status queries
- **Foreign key constraints** with cascade delete

### H. Role-Based Access Control
- **admin** - Full event management (create, update, delete)
- **agent** - Event creation/update (not deletion)
- **user** - Basic interactions (like, view, purchase)

### I. Social Authentication
- Google OAuth integration via Socialite
- Automatic user creation from Google profile
- Email auto-verification for OAuth users
- SSL bypass for local development

### J. Anti-XSS Protection
- voku/anti-xss package included
- Available for content sanitization
- Protects against malicious input

---

## 📈 Database Indexes - Performance Optimization

### event_views Table
- `idx_user_event_viewed` - (user_id, event_id, viewed_at)
- `idx_event_viewed` - (event_id, viewed_at)
- `idx_user` - (user_id)

### event_likes Table
- `unique_user_event_like` - (user_id, event_id) unique
- `idx_user_likes` - (user_id)
- `idx_event_likes` - (event_id)

### purchases Table
- `idx_user_event_purchase` - (user_id, event_id)
- `idx_user_created` - (user_id, created_at)
- `idx_status` - (status)
- `idx_event_purchase` - (event_id)

### events Table
- `idx_event_status` - (status) for filtering active events

---

## 🔍 API Pagination & Filtering

### Default Pagination
- 10 items per page
- Max 100 items per page

### Sorting Options
- `sort=date-asc` - Earliest events first
- `sort=date-desc` - Latest events first
- `sort=price-asc` - Cheapest first
- `sort=price-desc` - Most expensive first
- Default: Latest events first

### Filtering
- `category_id=1` - Filter by category
- `subcategory_id=1` - Filter by subcategory
- `date_from=2025-01-01` - Events from date
- `date_to=2025-12-31` - Events until date
- `featured=1` - Only featured events

### Response Meta
```json
{
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "per_page": 10,
    "to": 10,
    "total": 50
  },
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": "..."
  }
}
```

---

## ⚠️ Error Handling & Validation

### Validation Example (Event Creation)
- Title: required, string, 5-100 chars
- Description: required, string, 20-5000 chars
- Category: required, exists in DB
- Date: required, future date
- Price: required, numeric, 0-100000
- Images: required, JPEG/PNG/GIF, max 5MB
- Ticket Types: required array with price, quantity validation
- FAQs: optional array

### Error Responses
```json
{
  "error": "Failed to create event",
  "message": "Detailed exception message"
}
```

### HTTP Status Codes
- 200 - Success
- 201 - Created
- 401 - Unauthenticated
- 403 - Unauthorized
- 404 - Not found
- 422 - Validation failed
- 500 - Server error

---

## 🛡️ Security Features Implemented

✅ **CSRF Protection** - Sanctum CSRF tokens  
✅ **XSS Prevention** - Anti-XSS package, input sanitization  
✅ **SQL Injection Prevention** - Eloquent ORM with parameterized queries  
✅ **Authentication** - Session + Bearer tokens  
✅ **Authorization** - Role-based access control  
✅ **CORS Whitelist** - Specific origin validation  
✅ **Rate Limiting** - Throttle middleware (60/min for tracking)  
✅ **Password Hashing** - Laravel bcrypt hashing  
✅ **Input Validation** - Comprehensive form validation  
✅ **Secure Cookies** - HttpOnly flag set  

---

## 🏗️ Project Structure Summary

```
kakabackend/
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/        [5 controllers]
│   │   │   ├── AuthController.php
│   │   │   ├── EventController.php
│   │   │   ├── CategoryController.php
│   │   │   ├── SubCategoryController.php
│   │   │   └── RecommendationController.php
│   │   ├── Middleware/             [3 custom middlewares]
│   │   │   ├── Authenticate.php
│   │   │   ├── Cors.php
│   │   │   └── RedirectIfAuthenticated.php
│   │   └── Kernel.php
│   ├── Models/                     [10 models with relationships]
│   │   ├── User.php
│   │   ├── Event.php
│   │   ├── Category.php
│   │   ├── SubCategory.php
│   │   ├── Organizer.php
│   │   ├── EventAddress.php
│   │   ├── TicketType.php
│   │   ├── Faq.php
│   │   ├── EventOption.php
│   │   ├── EventLike.php
│   │   ├── EventView.php
│   │   └── Purchase.php
│   ├── Services/                   [RecommendationEngine]
│   │   └── RecommendationEngine.php
│   └── Providers/
│       ├── AppServiceProvider.php
│       ├── AuthServiceProvider.php
│       └── RouteServiceProvider.php
├── database/
│   ├── migrations/                 [19 migration files]
│   ├── factories/                  [9 model factories]
│   └── seeders/                    [4 seeder files]
├── routes/
│   ├── api.php                     [All API routes]
│   └── web.php                     [Welcome route]
├── config/
│   ├── app.php
│   ├── auth.php
│   ├── cors.php
│   ├── sanctum.php
│   ├── permission.php
│   ├── database.php
│   ├── filesystems.php
│   ├── mail.php
│   └── ... [other config files]
├── resources/
│   ├── css/
│   ├── js/
│   └── views/
├── tests/                          [Pest testing framework]
├── storage/                        [Logs, uploads, cache]
├── public/                         [Web root]
├── vendor/                         [Dependencies]
├── composer.json                   [PHP dependencies]
├── package.json                    [Node dependencies]
├── vite.config.js                  [Frontend build]
├── phpunit.xml
└── artisan
```

---

## 📋 Summary - Key Takeaways

This is a **production-ready event ticketing platform** with:

1. **Robust Database:** 19 migrations, complex relationships, optimized indexes
2. **Smart Recommendations:** ML-like algorithm with caching (purchases 10x weight, likes 5x, views 1x)
3. **Complete Authentication:** Session + Google OAuth with role-based access
4. **Event Management:** CRUD operations with image upload, ticket types, FAQs
5. **User Interactions:** Like/unlike with tracking, view analytics, purchase history
6. **Frontend Integration:** CORS configured, Sanctum auth, example code provided
7. **Security:** CSRF, XSS protection, input validation, rate limiting
8. **Performance:** Query optimization, caching layer, pagination
9. **Testing Ready:** Pest framework, factories, seeders with 14 categories
10. **Extensible:** Permission system ready, OAuth framework, custom event options

### Current Limitations
- Payment processing not implemented (structure ready)
- Image optimization missing (no resize/compression)
- Real-time features not implemented (WebSockets ready for expansion)
- Email notifications not configured
- API documentation/Swagger not generated

---

## 🚀 Quick Reference - Common Tasks

### Get All Events
```
GET /api/events?sort=date-desc&category_id=1
```

### Create Event (Admin Only)
```
POST /api/events
Headers: Authorization: Bearer {token}
Body: {event data, images, ticket types, FAQs}
```

### Like Event
```
POST /api/events/{id}/toggle-like
Headers: Authorization: Bearer {token}
```

### Get Recommendations
```
GET /api/recommendations
Headers: Authorization: Bearer {token}
```

### Track View
```
POST /api/events/{id}/track-view
Throttled to 60 requests/min
```

### Login
```
POST /api/login
Body: { email, password }
```

### Register
```
POST /api/register
Body: { name, email, password, password_confirmation }
```
