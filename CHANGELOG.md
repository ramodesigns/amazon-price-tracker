# Changelog

All notable changes to the Amazon Price Tracker plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-12-06

### Added

#### Core Plugin
- WordPress REST API plugin for tracking Amazon product prices
- Support for all 12 Amazon international marketplaces (US, CA, UK, DE, FR, ES, IT, AU, JP, IN, MX, BR)
- WordPress Application Passwords authentication (HTTP Basic Auth)
- Role-based access control (Administrator vs Standard users)
- Daily rate limiting (50 products/day for non-admin users)

#### Database
- `apt_products` table for tracked ASIN/Region combinations with metadata
- `apt_price_history` table for historical price tracking
- `apt_user_settings` table for per-user Amazon PA-API credentials
- `apt_blacklist` table for blocked ASIN/Region combinations
- Optimized composite indexes for common query patterns

#### Amazon PA-API Integration
- Amazon Product Advertising API 5.0 client
- AWS Signature Version 4 authentication
- Automatic product data extraction (images, facts, pricing)
- Support for bulk operations (up to 10 ASINs per API call)

#### REST API Endpoints
- **Health**: `GET /health` (public), `GET /health/amazon`
- **Regions**: `GET /regions`
- **Settings**: `GET/PUT /settings`, `DELETE /settings/partner-tags/{region}`, `POST /settings/validate`
- **Products**: Full CRUD with filtering, pagination, and sorting
  - `GET /products` with 12 filter parameters
  - `POST /products` and `POST /products/bulk`
  - `GET/DELETE /products/{id}`
  - `PUT /products/{id}/category`
  - `GET /products/{id}/prices` with aggregations (daily/weekly/monthly)
  - `POST /products/{id}/refresh` and `POST /products/refresh`
  - `GET /products/by-asin/{asin}` and `GET /products/by-asin/{asin}/{region}`
- **Categories**: `GET /categories` (admin only)
- **Blacklist**: Full CRUD + `GET /blacklist/check`
- **Statistics**: `GET /stats`, `GET /stats/user`

#### Scheduled Tasks
- WP-Cron integration for automated price refreshes
- Configurable schedule (hourly, 6h, 12h, twice daily, daily)
- Configurable batch size (10-500 products per run)
- Admin UI for schedule management

#### Security
- AES-256-CBC encryption for stored API credentials
- Prepared statements for all database queries
- ABSPATH checks on all PHP files
- Nonce validation on admin forms
- Capability checks on admin-only endpoints
- Secret keys never exposed in API responses

#### Admin Interface
- Settings page under Settings > Amazon Price Tracker
- API endpoint information display
- Scheduled refresh configuration
- Manual refresh trigger
- Quick stats overview

#### Documentation
- Postman API collection (`docs/api-collection.json`)
- Pre-production task checklist (`docs/pendingTasks.txt`)
- Plugin readme (`readme.txt`)

### Security
- All user inputs sanitized and validated
- SQL injection prevention via prepared statements
- XSS prevention via proper escaping
- CSRF protection via nonces on admin forms

---

## [Unreleased]

### Planned
- Webhook notifications for price drops
- CSV/JSON export for price history
- Product import from CSV
- Email notifications for refresh failures
- REST API rate limiting headers (X-RateLimit-*)
