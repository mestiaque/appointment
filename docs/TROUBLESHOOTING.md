# Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Booking menu missing | The user's metheme role lacks `abs_*` permissions. Assign a Booking role. |
| Redirected to "Businesses" with an error | The user has no active membership. Add them under Team Members, or create a business. |
| 404 on a record that exists | It belongs to another business than the one selected in the business switcher. |
| No times on the booking page | The staff member has no working hours, does not offer the service, or does not accept bookings. The minimum notice or max days ahead hides them. A holiday applies. The service duration is longer than the shift. |
| Times look shifted | Check the business timezone. `app.timezone` must be UTC. |
| E-mails not arriving | Metheme Mail Configuration is not set, so mail goes to `storage/logs`. The queue worker is not running. Run `php artisan queue:restart` after changing settings. See `abs_notification_logs` for status and errors. |
| Reminders not sent | Cron for `schedule:run` is missing. Reminder minutes is 0 in Booking Settings. |
| Unpaid bookings stay "Awaiting payment" | The scheduler is not running (Diagnostics warns). Availability is still correct, because expired holds never block. |
| Webhook returns 400 | Wrong `STRIPE_WEBHOOK_SECRET`, the server clock is skewed (more than 300 s), or a proxy modifies the body. |
| Webhook returns 500 | Processing failed and Stripe will retry. See `abs_webhook_events.error` and the Laravel log. |
| "Unable to find component: [abs-…]" | Run `composer dump-autoload` and `php artisan optimize:clear`. Livewire must be installed. |
| Guest manage links stopped working | `APP_KEY` changed; signed links depend on it. |
| Deadlock or lock wait timeout in logs | Retried automatically (3 attempts). If frequent, check slow queries and `innodb_lock_wait_timeout`. |
