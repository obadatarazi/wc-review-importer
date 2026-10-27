=== WC Review Importer ===
Contributors:      yourname
Tags:              woocommerce, reviews, import, csv, bulk
Requires at least: 6.0
Tested up to:      6.7
Stable tag:        1.1.6
Requires PHP:      8.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Bulk import WooCommerce product reviews from CSV or Excel. Supports Arabic/UTF-8, verified owner toggle, image sideloading, duplicate detection, and CSV export.

== Description ==

**WC Review Importer** gives you a polished admin dashboard inside WooCommerce to:

* **Bulk import** reviews from CSV or XLSX (Excel) files
* Add reviews without requiring a registered customer or purchase history
* Map reviews to products by **Product ID** or **SKU**
* Support full reviewer details: name, email, rating (1–5), content, date
* Auto-publish reviews on import
* Detect and skip **duplicate reviews** automatically
* Full **UTF-8 / Arabic character** support (BOM-safe CSV parsing)
* Optionally randomise review dates for rows with empty dates
* Toggle **Verified Owner** per-row or set a global default
* **Sideload review images** from remote URLs into the WordPress media library
* **Export** all reviews to a UTF-8 CSV for backup or migration
* **Manually add** single reviews from the dashboard
* Modern, responsive admin UI compatible with WoodMart theme

== Installation ==

1. Upload the `wc-review-importer` folder to `/wp-content/plugins/`
2. Activate through **Plugins → Installed Plugins**
3. Navigate to **WooCommerce → Review Importer**

== CSV Format ==

| Column          | Description                                         |
|-----------------|-----------------------------------------------------|
| product_id      | Numeric WooCommerce product ID                      |
| sku             | Product SKU (alternative to product_id)             |
| reviewer_name   | Full name of the reviewer                           |
| reviewer_email  | Reviewer email address                              |
| rating          | Star rating 1–5                                     |
| review_content  | Review text (UTF-8, Arabic supported)               |
| review_date     | Any date string, or leave empty to randomise        |
| verified        | 1 = verified owner, 0 = not verified                |
| image_url       | Optional public URL of an image to attach           |

Download a ready-to-use sample from the plugin dashboard.

== Frequently Asked Questions ==

= Does it work without WooCommerce? =
No. WooCommerce must be active.

= Can I import reviews for non-registered customers? =
Yes. There is no purchase or registration requirement.

= Is PhpSpreadsheet required for XLSX? =
For full XLSX support, install PhpSpreadsheet via Composer. As a fallback the plugin will attempt to parse the file as CSV.

= Does it conflict with WoodMart? =
No. The plugin only adds an admin menu page and injects standard WooCommerce comments.

== Changelog ==

= 1.1.6 =
* AJAX add review, bulk import, and delete (no page reload).
* Renamed branding from "Review Importer Pro" to "Review Importer".
* Avatar preview on upload; inline success/error notices.

= 1.1.5 =
* Optional reviewer avatar upload (shown on product reviews).
* Review content is optional (rating-only reviews supported).
* Bulk import: optional avatar_url CSV column.

= 1.1.4 =
* Fix critical error when saving a single review (use WC_Comments::clear_transients).

= 1.1.3 =
* Add Review tab is first and default.
* Single-review form: searchable multi-product select (SKU field removed).
* Admin UI restyled to match WordPress admin colors and components.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.3 =
UI improvements, multi-product single review, and tab order updates.

= 1.0.0 =
First public release.
