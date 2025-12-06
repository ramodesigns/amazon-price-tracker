=== Amazon Price Tracker ===
Contributors: ramodesigns
Tags: amazon, price tracker, product advertising api, affiliate, price history
Requires at least: 5.9
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WordPress REST API plugin for tracking Amazon product prices across multiple international marketplaces.

== Description ==

Amazon Price Tracker is a powerful WordPress plugin that provides a REST API for tracking Amazon product prices across 12 international marketplaces. It integrates with Amazon Product Advertising API 5.0 to fetch real-time pricing and product information, storing historical data for price tracking over time.

= Features =

* **12 Amazon Marketplaces** - Track products from US, UK, DE, FR, ES, IT, CA, AU, JP, IN, MX, and BR
* **REST API** - Full REST API accessible via WordPress REST API (WP REST API)
* **Price History** - Store and retrieve historical price data with aggregations
* **Role-Based Access** - Different permissions for standard users and administrators
* **Rate Limiting** - Built-in rate limiting (50 products/day for non-admin users)
* **Blacklist Management** - Administrators can blacklist specific ASIN/Region combinations
* **Scheduled Refresh** - Automatic price updates via WP-Cron
* **Secure Credentials** - Encrypted storage of Amazon PA-API credentials

= Supported Regions =

| Region | Country | Domain | Currency |
|--------|---------|--------|----------|
| US | United States | amazon.com | USD |
| CA | Canada | amazon.ca | CAD |
| UK | United Kingdom | amazon.co.uk | GBP |
| DE | Germany | amazon.de | EUR |
| FR | France | amazon.fr | EUR |
| ES | Spain | amazon.es | EUR |
| IT | Italy | amazon.it | EUR |
| AU | Australia | amazon.com.au | AUD |
| JP | Japan | amazon.co.jp | JPY |
| IN | India | amazon.in | INR |
| MX | Mexico | amazon.com.mx | MXN |
| BR | Brazil | amazon.com.br | BRL |

= API Endpoints =

* `GET /regions` - List supported marketplaces
* `GET/PUT /settings` - Manage user API credentials
* `GET/POST /products` - List and create tracked products
* `POST /products/bulk` - Bulk create products
* `POST /products/refresh` - Refresh prices (admin only)
* `GET /products/{id}` - Get product details
* `GET /products/{id}/prices` - Get price history
* `GET /categories` - List custom categories (admin only)
* `GET/POST /blacklist` - Manage blacklist (admin only)
* `GET /stats` - API statistics
* `GET /health` - Health check (public)

== Installation ==

1. Upload the `amazon-price-tracker` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Amazon Price Tracker to configure scheduled refresh options
4. Create a WordPress Application Password for API authentication
5. Configure your Amazon PA-API credentials via the REST API

= Amazon PA-API Setup =

1. Sign up for the [Amazon Associates Program](https://affiliate-program.amazon.com/)
2. Register for [Product Advertising API access](https://webservices.amazon.com/paapi5/documentation/register-for-pa-api.html)
3. Create Access Key and Secret Key in your Amazon Associates account
4. Note your Partner Tag for each marketplace you want to use

= API Authentication =

The API uses WordPress Application Passwords for authentication:

1. Go to Users > Your Profile in WordPress admin
2. Scroll to "Application Passwords"
3. Create a new application password
4. Use HTTP Basic Auth with your username and application password

Example with cURL:
`curl -u "username:application_password" https://yoursite.com/wp-json/amazon-price-tracker/v1/health`

== Frequently Asked Questions ==

= What is Amazon PA-API? =

Amazon Product Advertising API (PA-API) is Amazon's official API for accessing product information including prices, images, and descriptions. You need to be an Amazon Associate to access it.

= Why do I need partner tags for each region? =

Amazon requires a valid partner tag (affiliate ID) for each marketplace when making API requests. You need to register with each regional Amazon Associates program.

= What are the rate limits? =

Standard users can create up to 50 products per day. Administrators have no limits. The daily limit resets at midnight UTC.

= How often are prices refreshed? =

By default, prices are refreshed twice daily via WP-Cron. You can adjust this in Settings > Amazon Price Tracker (options: hourly, 6 hours, 12 hours, twice daily, daily, or disabled).

= Is the API secure? =

Yes. All endpoints (except /health) require authentication via WordPress Application Passwords. Amazon credentials are encrypted before storage.

== Changelog ==

= 1.0.0 =
* Initial release
* Full REST API for product and price management
* Amazon PA-API 5.0 integration
* Support for 12 Amazon marketplaces
* Price history with aggregations
* Scheduled price refresh
* Admin settings page
* Role-based permissions
* Blacklist management

== Upgrade Notice ==

= 1.0.0 =
Initial release of Amazon Price Tracker.
