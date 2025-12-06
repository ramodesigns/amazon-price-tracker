# Amazon Price Tracker API - Test Checklist

Manual QA checklist for pre-production testing. Each section contains test cases with expected results.

## Prerequisites

Before testing:
1. [ ] WordPress 5.9+ installed with PHP 7.4+
2. [ ] Plugin activated (verify tables exist)
3. [ ] Admin user with Application Password created
4. [ ] Test data seeded: `wp eval-file tests/seed-test-data.php`
5. [ ] API client ready (Postman collection: `docs/api-collection.json`)

---

## 1. Health Endpoints

### GET /health (Public)
| Test Case | Expected | Pass |
|-----------|----------|------|
| No authentication | 200 OK | [ ] |
| Response has `status: "healthy"` | ✓ | [ ] |
| Response has `version`, `wordpress_version`, `php_version` | ✓ | [ ] |
| Response has `timestamp` in ISO 8601 format | ✓ | [ ] |

### GET /health/amazon
| Test Case | Expected | Pass |
|-----------|----------|------|
| Without auth | 401 Unauthorized | [ ] |
| With auth, no credentials configured | `status: "not_configured"` | [ ] |
| With auth, valid credentials | `status: "connected"` + `response_time_ms` | [ ] |
| With auth, invalid credentials | `status: "error"` | [ ] |

---

## 2. Regions Endpoint

### GET /regions
| Test Case | Expected | Pass |
|-----------|----------|------|
| Without auth | 401 Unauthorized | [ ] |
| With auth | 200 OK, array of 12 regions | [ ] |
| Each region has `code`, `name`, `marketplace_domain`, `currency` | ✓ | [ ] |
| Includes US, UK, DE, FR, ES, IT, CA, AU, JP, IN, MX, BR | ✓ | [ ] |

---

## 3. Settings Endpoints

### GET /settings
| Test Case | Expected | Pass |
|-----------|----------|------|
| Without auth | 401 Unauthorized | [ ] |
| No settings configured | 404 Not Found | [ ] |
| Settings exist | 200 OK with masked `access_key` | [ ] |
| `secret_key` NOT in response | ✓ | [ ] |
| `partner_tags` object returned | ✓ | [ ] |

### PUT /settings
| Test Case | Expected | Pass |
|-----------|----------|------|
| First setup with `access_key` + `secret_key` | 201 Created | [ ] |
| Update existing settings | 200 OK | [ ] |
| Add new partner tag (merged) | Partner tags merged | [ ] |
| Missing `access_key` on first setup | 400 Validation Error | [ ] |
| Invalid region in partner_tags | 400 Validation Error | [ ] |

### DELETE /settings/partner-tags/{region}
| Test Case | Expected | Pass |
|-----------|----------|------|
| Delete existing partner tag | 204 No Content | [ ] |
| Delete non-existent partner tag | 404 Not Found | [ ] |
| Invalid region code | 404 Not Found | [ ] |

### POST /settings/validate
| Test Case | Expected | Pass |
|-----------|----------|------|
| No credentials configured | 400 NOT_CONFIGURED | [ ] |
| Valid credentials | `valid: true` | [ ] |
| Invalid credentials | `valid: false` | [ ] |

---

## 4. Products Endpoints

### GET /products
| Test Case | Expected | Pass |
|-----------|----------|------|
| Without auth | 401 Unauthorized | [ ] |
| Default request | 200 OK, paginated list | [ ] |
| Pagination: `page=2&per_page=5` | Correct offset/limit | [ ] |
| Filter: `region=US` | Only US products | [ ] |
| Filter: `regions=US,UK` | US and UK products | [ ] |
| Filter: `custom_category=Electronics` | Exact match | [ ] |
| Filter: `search=Sony` (min 2 chars) | Title/brand search | [ ] |
| Filter: `min_price=50&max_price=100` | Price range | [ ] |
| Filter: `availability=in_stock` | Only in-stock | [ ] |
| Filter: `is_active=false` | Soft-deleted products | [ ] |
| Sort: `sort_by=current_price&sort_order=asc` | Price ascending | [ ] |
| Response has `data` array + `meta.pagination` | ✓ | [ ] |

### POST /products
| Test Case | Expected | Pass |
|-----------|----------|------|
| Valid ASIN + region | 201 Created with product | [ ] |
| Invalid ASIN format (not 10 chars) | 400 VALIDATION_ERROR | [ ] |
| Invalid region code | 400 VALIDATION_ERROR | [ ] |
| Duplicate ASIN/region | 409 ALREADY_EXISTS (with existing ID) | [ ] |
| Blacklisted ASIN/region | 403 BLACKLISTED (with reason) | [ ] |
| Missing partner tag for region | 400 MISSING_PARTNER_TAG | [ ] |
| ASIN not found on Amazon | 400 ASIN_NOT_FOUND | [ ] |
| Rate limit exceeded (non-admin, 51st) | 429 RATE_LIMIT_EXCEEDED | [ ] |
| Admin bypasses rate limit | 201 Created | [ ] |

### POST /products/bulk
| Test Case | Expected | Pass |
|-----------|----------|------|
| Array of 3 valid products | 200 OK, `success_count: 3` | [ ] |
| Mix of valid/invalid | Partial success with results | [ ] |
| Empty products array | 400 Validation Error | [ ] |
| More than 100 products | 400 Validation Error | [ ] |
| Counts against rate limit | ✓ | [ ] |

### GET /products/{id}
| Test Case | Expected | Pass |
|-----------|----------|------|
| Valid ID | 200 OK with full product | [ ] |
| Non-existent ID | 404 NOT_FOUND | [ ] |
| Response has `images`, `facts` arrays | ✓ | [ ] |

### DELETE /products/{id} (Admin)
| Test Case | Expected | Pass |
|-----------|----------|------|
| As non-admin | 403 Forbidden | [ ] |
| Soft delete (default) | 204, `is_active=0`, history kept | [ ] |
| Hard delete (`force=true`) | 204, product + history deleted | [ ] |
| Non-existent ID | 404 NOT_FOUND | [ ] |

### PUT /products/{id}/category (Admin)
| Test Case | Expected | Pass |
|-----------|----------|------|
| As non-admin | 403 Forbidden | [ ] |
| Set category | 200 OK, category updated | [ ] |
| Set to `null` | 200 OK, category removed | [ ] |
| Non-existent ID | 404 NOT_FOUND | [ ] |

### GET /products/{id}/prices
| Test Case | Expected | Pass |
|-----------|----------|------|
| Valid product ID | 200 OK with price records | [ ] |
| Response has `product`, `currency`, `data`, `meta` | ✓ | [ ] |
| Filter: `from` and `to` dates | Date range filter | [ ] |
| Aggregate: `aggregate=daily` | `aggregations` array included | [ ] |
| Aggregate: `aggregate=weekly` | Weekly periods | [ ] |
| Aggregate: `aggregate=monthly` | Monthly periods | [ ] |
| Aggregations have min/max/avg for price and rrp | ✓ | [ ] |
| Sort: `sort_order=asc` | Oldest first | [ ] |

### POST /products/{id}/refresh (Admin)
| Test Case | Expected | Pass |
|-----------|----------|------|
| As non-admin | 403 Forbidden | [ ] |
| Valid refresh | 200 OK, new price record created | [ ] |
| Amazon API failure | 502 AMAZON_API_ERROR | [ ] |
| Non-existent ID | 404 NOT_FOUND | [ ] |

### POST /products/refresh (Admin - Bulk)
| Test Case | Expected | Pass |
|-----------|----------|------|
| As non-admin | 403 Forbidden | [ ] |
| Refresh all (no params) | 200 OK with results | [ ] |
| Filter by `product_ids` | Only specified products | [ ] |
| Filter by `regions` | Only specified regions | [ ] |
| Limit: `limit=10` | Max 10 refreshed | [ ] |

### GET /products/by-asin/{asin}
| Test Case | Expected | Pass |
|-----------|----------|------|
| ASIN tracked in multiple regions | Array of products | [ ] |
| ASIN not tracked | 404 NOT_FOUND | [ ] |

### GET /products/by-asin/{asin}/{region}
| Test Case | Expected | Pass |
|-----------|----------|------|
| Valid ASIN/region | 200 OK with product | [ ] |
| Not tracked | 404 NOT_FOUND | [ ] |

### GET /products/by-asin/{asin}/{region}/prices
| Test Case | Expected | Pass |
|-----------|----------|------|
| Same params as /products/{id}/prices | Same behavior | [ ] |

---

## 5. Categories Endpoint (Admin)

### GET /categories
| Test Case | Expected | Pass |
|-----------|----------|------|
| As non-admin | 403 Forbidden | [ ] |
| As admin | 200 OK, array of categories | [ ] |
| Each has `name` and `count` | ✓ | [ ] |
| Alphabetically sorted | ✓ | [ ] |

---

## 6. Blacklist Endpoints (Admin)

### GET /blacklist
| Test Case | Expected | Pass |
|-----------|----------|------|
| As non-admin | 403 Forbidden | [ ] |
| As admin | 200 OK, paginated list | [ ] |
| Filter: `region=US` | Only US entries | [ ] |
| Filter: `search=B08` | ASIN search | [ ] |

### POST /blacklist
| Test Case | Expected | Pass |
|-----------|----------|------|
| As non-admin | 403 Forbidden | [ ] |
| Valid ASIN/region | 201 Created | [ ] |
| Existing product gets soft-deleted | ✓ | [ ] |
| Duplicate entry | 409 ALREADY_EXISTS | [ ] |
| With optional `reason` | Reason stored | [ ] |

### GET /blacklist/check
| Test Case | Expected | Pass |
|-----------|----------|------|
| As non-admin | 403 Forbidden | [ ] |
| Blacklisted ASIN/region | `blacklisted: true` + `entry` | [ ] |
| Not blacklisted | `blacklisted: false` | [ ] |

### GET /blacklist/{id}
| Test Case | Expected | Pass |
|-----------|----------|------|
| As non-admin | 403 Forbidden | [ ] |
| Valid ID | 200 OK with entry | [ ] |
| Invalid ID | 404 NOT_FOUND | [ ] |

### DELETE /blacklist/{id}
| Test Case | Expected | Pass |
|-----------|----------|------|
| As non-admin | 403 Forbidden | [ ] |
| Valid ID | 204 No Content | [ ] |
| Invalid ID | 404 NOT_FOUND | [ ] |

---

## 7. Statistics Endpoints

### GET /stats
| Test Case | Expected | Pass |
|-----------|----------|------|
| Without auth | 401 Unauthorized | [ ] |
| As standard user | 200 OK (no `last_refresh`) | [ ] |
| As admin | 200 OK (includes `last_refresh`) | [ ] |
| Has `total_products`, `active_products` | ✓ | [ ] |
| Has `products_by_region` array | ✓ | [ ] |
| Has `user_stats` with daily limit info | ✓ | [ ] |

### GET /stats/user
| Test Case | Expected | Pass |
|-----------|----------|------|
| Without auth | 401 Unauthorized | [ ] |
| As standard user | `daily_limit: 50`, `is_admin: false` | [ ] |
| As admin | `daily_limit: null`, `is_admin: true` | [ ] |
| Has `products_created_today`, `remaining_today` | ✓ | [ ] |
| Has `configured_regions` array | ✓ | [ ] |

---

## 8. Error Response Format

Verify all error responses follow the standard format:

```json
{
  "code": "ERROR_CODE",
  "message": "Human readable message",
  "details": { }
}
```

| Error Type | HTTP Status | Code | Pass |
|------------|-------------|------|------|
| Not authenticated | 401 | UNAUTHORIZED | [ ] |
| Not admin | 403 | FORBIDDEN | [ ] |
| Not found | 404 | NOT_FOUND | [ ] |
| Validation error | 400 | VALIDATION_ERROR | [ ] |
| Already exists | 409 | ALREADY_EXISTS | [ ] |
| Rate limit | 429 | RATE_LIMIT_EXCEEDED | [ ] |
| Amazon API error | 502 | AMAZON_API_ERROR | [ ] |

---

## 9. Rate Limiting Tests

| Test Case | Expected | Pass |
|-----------|----------|------|
| Standard user: Create product #1-50 | All succeed | [ ] |
| Standard user: Create product #51 | 429 with limit info | [ ] |
| Rate limit resets at midnight UTC | Counter resets | [ ] |
| Admin: No rate limit | Unlimited creates | [ ] |
| Bulk create counts against limit | ✓ | [ ] |

---

## 10. Admin UI Tests

Access: WordPress Admin → Settings → Amazon Price Tracker

| Test Case | Expected | Pass |
|-----------|----------|------|
| Page loads without errors | ✓ | [ ] |
| Shows API endpoint URL | ✓ | [ ] |
| Schedule dropdown works | ✓ | [ ] |
| Batch size input validates (10-500) | ✓ | [ ] |
| Save Settings button works | Success message | [ ] |
| Manual Refresh button works | Success message with counts | [ ] |
| Quick Stats shows product/price counts | ✓ | [ ] |

---

## Sign-Off

| Tester | Date | Environment | Result |
|--------|------|-------------|--------|
| | | | |

**Notes:**
