=== Terminarz ===
Contributors: michalstaniecko
Tags: booking, appointments, reservations, calendar, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Online appointment booking for service businesses: resources, working hours, a booking block, e-mails and optional WooCommerce payments.

== Description ==

Terminarz lets customers book appointments on your site: a treatment room, a therapist, an instructor or a meeting room.
You describe what can be booked and when, customers pick a free slot in a Gutenberg block, and the plugin takes care of
confirmations, reminders and cancellations.

= Features =

* **Resources and services** – people, rooms or equipment; services with a duration, a buffer after the appointment and a price.
  A service can be performed by several resources; customers pick one or let the plugin choose ("any resource").
* **Working hours and exceptions** – a weekly schedule per resource (with breaks), days off, holidays and one-off opening hours (Terminarz → Days off).
* **Availability engine** – minimum lead time, booking horizon, slot grid, buffers, daylight saving time handled correctly.
  All times are stored in UTC and shown in the site time zone.
* **Booking block** – the "Booking" block: service → resource → date → time → customer details, keyboard accessible,
  with a required consent checkbox and spam protection (honeypot and request limits).
* **No double bookings** – a slot is reserved atomically in the database, even when two customers click at the same moment.
* **Administration** – booking list with filters, search, CSV export, confirmation, cancellation and rescheduling.
* **E-mails** – seven editable templates (booking received, confirmed, reminder, cancelled and notifications for the business)
  with placeholders, preview and test sending (Terminarz → E-mails).
* **Reminders** – sent a configurable number of hours before the appointment (Action Scheduler when WooCommerce is active,
  WP-Cron otherwise).
* **Cancellation link** – customers cancel from a secure link in the e-mail, up to the cancellation limit set in the settings.
* **Optional WooCommerce payments** – full payment or a deposit through any WooCommerce payment gateway. The slot is held
  while the customer pays and released when the payment is not completed in time. Without WooCommerce the plugin works
  without payments.
* **Privacy tools** – personal data export and erasure for bookings, suggested privacy policy text.
* **Multisite** – works per site and with network activation.
* **Translation ready** – Polish translation included.

== Installation ==

1. Upload the `terminarz` folder to `/wp-content/plugins/` or install the ZIP file in Plugins → Add New → Upload Plugin.
2. Activate the plugin (network activation is supported on multisite).
3. Go to Terminarz → Resources and add at least one resource, then set its working hours in Terminarz → Working hours.
4. Add services in Terminarz → Services and assign resources to them.
5. Review Terminarz → Settings (lead time, horizon, cancellation limit, automatic confirmation, payments).
6. Insert the "Booking" block (Widgets category) on a page.

Optional: install and activate WooCommerce 8.0 or newer and choose a payment mode in Terminarz → Settings to accept online payments.

== Frequently Asked Questions ==

= Do I need WooCommerce? =

No. Without WooCommerce bookings are accepted without payment. With WooCommerce 8.0+ you can require a full payment or
a deposit; payments, refunds and invoices are handled by WooCommerce.

= Who can manage bookings? =

Users with the `trmz_manage_bookings` capability. On activation it is granted to administrators; use a role editor
plugin to grant it to other roles (for example to reception staff).

= Are bookings confirmed automatically? =

Only when "Automatic confirmation" is enabled in the settings. Otherwise a new booking is pending until an administrator
confirms it. Paid bookings are confirmed when the payment is completed.

= What happens when the customer does not pay? =

The slot is held for the time set in "Slot hold for payment". When the hold expires the booking expires, the slot becomes
free again and the unpaid WooCommerce order is cancelled.

= How does the customer cancel a booking? =

Every booking e-mail can contain the `{cancel_url}` link (included in the default templates). The link opens a
confirmation page on your site (`/?trmz_cancel=…&token=…`); cancellation is possible until the "Customer cancellation
limit" before the appointment. Paid orders are not refunded automatically – the business is notified and decides.
Links are signed with the WordPress `AUTH_SALT`: changing the salts invalidates links sent earlier.

= My site is behind a reverse proxy or a CDN. What should I change? =

Public booking and cancellation requests are rate limited per client IP address. By default only `REMOTE_ADDR` is trusted,
because proxy headers such as `X-Forwarded-For` can be forged by anyone. Behind a reverse proxy or CDN every visitor has
the proxy address, so all customers would share one limit. Return the real client address with the `trmz_client_ip`
filter, reading only a header your proxy sets and overwrites, for example:

`add_filter( 'trmz_client_ip', function ( $ip ) {
	return isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) : $ip;
} );`

The limits themselves can be changed with the `trmz_rate_limit` filter (default: 5 requests per 10 minutes per client).

= Are reminders sent on time? =

With WooCommerce active reminders use Action Scheduler; otherwise WP-Cron, which runs only when someone visits the site.
For punctual reminders on low-traffic sites run WP-Cron from a real system cron job.

= What is removed when I delete the plugin? =

Scheduled jobs are always removed. Bookings, resources, services, settings and e-mail templates are deleted only when
"Delete data on uninstall" is enabled in Terminarz → Settings (on multisite: per site). WooCommerce orders are never deleted.

== Privacy ==

Terminarz stores the data customers enter in the booking form: name, e-mail address, optional phone number and note, the
booked service, resource and time, and – for logged-in customers – the user ID. The data is stored in your site's database
and is not sent to any external service by the plugin. E-mails are sent through `wp_mail()`; payments are processed by
WooCommerce and the payment gateway you configure.

* Consent: the booking form requires the customer to accept the consent text set in the settings (for example a link to
  your privacy policy).
* The IP address is used only for rate limiting, as a keyed hash in a short-lived transient; it is not stored with the booking.
* Personal data export and erasure (Tools → Export/Erase Personal Data) include bookings matched by e-mail address.
  Erasure anonymises past and inactive bookings; upcoming active bookings are kept until they are cancelled.
* A suggested privacy policy paragraph is available in Settings → Privacy → Policy guide.

You are responsible for the legal basis of processing and for your privacy policy (for example under the GDPR).

== Developers ==

REST API namespace: `terminarz/v1` (`/services`, `/resources`, `/availability`, `/bookings`; management endpoints require
the `trmz_manage_bookings` capability).

= Actions =

* `trmz_booking_created( Booking $booking )` – a booking was created.
* `trmz_booking_status_changed( Booking $booking, BookingStatus $previous )` – status changed (confirmed, cancelled, expired, completed…).
* `trmz_booking_rescheduled( Booking $booking, Booking $previous )` – a booking was moved to another time or resource.
* `trmz_payment_needs_attention( WC_Order $order, Booking $booking, string $reason )` – a paid order could not be matched with a free slot.
* `trmz_payment_start_failed( Booking $booking, Exception $error )` – the online payment of a new booking could not be started.
* `trmz_schema_migrated( string $version )` – the database schema was installed or upgraded.
* `trmz_woocommerce_integration_loaded` – the WooCommerce integration was loaded.
* `trmz_expire_holds`, `trmz_send_reminder( int $booking_id )` – scheduled jobs (do not call directly).

= Filters =

* `trmz_client_ip( string $ip, ?WP_REST_Request $request )` – client IP address used for rate limits (see FAQ).
* `trmz_rate_limit( array $config, string $bucket, ?WP_REST_Request $request )` – `limit` and `window` (seconds) for the `booking_create` and `booking_cancel` buckets.
* `trmz_auto_confirm_bookings( bool $auto_confirm, Service $service )` – confirm new bookings automatically.
* `trmz_availability_settings( AvailabilitySettings $settings )` – time zone, lead time, horizon, slot step, "any resource" strategy.
* `trmz_availability_cache_max_age( int $seconds )` – cache lifetime of availability responses (default 0, `no-store`).
* `trmz_booking_block_config( array $config, array $attributes )` – front-end configuration of the booking block.
* `trmz_email( array|false $mail, string $type, ?Booking $booking )` – an e-mail before sending; return false to skip it.
* `trmz_email_html( string $html, string $content, string $subject )` – the HTML layout of e-mails.
* `trmz_email_placeholders( array $values, Booking $booking )` – placeholder values of booking e-mails.
* `trmz_cancel_page_html( string $html, string $title, string $body )` – the customer cancellation page.
* `trmz_use_action_scheduler( bool $use )` – use Action Scheduler (when available) instead of WP-Cron.
* `trmz_payment_provider( PaymentProvider|null $provider, Services $services )` – replace the online payment provider.
* `trmz_woocommerce_active( bool $active, string $version )` – whether the WooCommerce integration is available.
* `trmz_currency( string $currency )`, `trmz_price_decimals( int $decimals )` – price display without WooCommerce.
* `trmz_csv_separator( string $separator )` – column separator of the CSV export.
* `trmz_admin_booking_details_rows( array $rows, Booking $booking )` – rows of the booking details screen (escape your HTML).

The block dispatches the DOM event `terminarz:booking-created` after a successful booking.

== Changelog ==

= 1.0.0 =
* First stable release.
* Resources, services, weekly schedules and exceptions; availability engine with buffers, lead time, horizon and DST support.
* Booking block with accessible step-by-step form, consent, honeypot and rate limiting; atomic slot reservation.
* Booking administration: list, filters, CSV export, confirmation, cancellation, rescheduling; settings screen.
* Optional WooCommerce payments (full or deposit), slot hold with automatic expiry, order status synchronisation.
* Editable e-mail templates, reminders, secure cancellation link.
* Personal data export/erasure, multisite support, uninstall cleanup (opt-in), Polish translation.

== Upgrade Notice ==

= 1.0.0 =
First stable release.
