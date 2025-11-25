# Backend-Frontend Communication Setup

This document outlines how this backend is configured to communicate with your frontend application.

## Local Development

For local development, the backend expects your frontend to be running at:
```
http://localhost:3000
```

The backend API will be accessible at:
```
http://localhost:8000/api
```

## Configuration

The following environment variables control the communication:

- `FRONTEND_URL`: The URL of your frontend application (default: http://localhost:3000)
- `CORS_ALLOWED_ORIGINS`: Comma-separated list of allowed origins (default: http://localhost:3000)
- `SANCTUM_STATEFUL_DOMAINS`: Domains that will receive stateful cookies for authentication (default: localhost:3000)
- `SESSION_DOMAIN`: Domain for cookies (leave empty for local development)

## Production Setup

For production, update your `.env` file with the following:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.yourdomain.com
FRONTEND_URL=https://yourdomain.com
CORS_ALLOWED_ORIGINS=https://yourdomain.com
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=yourdomain.com
```

## Authentication Flow

1. Frontend requests a CSRF cookie from `/sanctum/csrf-cookie` endpoint
2. Frontend includes the CSRF token in subsequent login/registration requests
3. After successful authentication, the backend sets session cookies
4. Frontend can then make authenticated API requests

## API Endpoints

All API endpoints are accessible under the `/api` prefix:

- GET `/api/user` - Returns the authenticated user (requires authentication)
- Various other endpoints defined in `routes/api.php`

## CORS Configuration

The CORS middleware is configured to:

- Allow requests from the specified origins (default: http://localhost:3000)
- Allow all common HTTP methods (GET, POST, PUT, DELETE, OPTIONS)
- Allow credentials (cookies) to be sent with requests
- Allow common headers including Authorization and X-XSRF-TOKEN

## Troubleshooting

If you encounter CORS issues:

1. Ensure your frontend is making requests with `credentials: 'include'`
2. Check that your backend URL is correctly set in your frontend app
3. Verify that the `CORS_ALLOWED_ORIGINS` includes your frontend URL
4. For production, ensure SSL is properly configured on both sides 
