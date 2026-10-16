# Cron events

`CronActions` stores events in WordPress's `cron` option. Event signatures use
`Cache::generate_key(...$args)`, matching WordPress lookup and unscheduling.
The helper's variadic arguments receive the event arguments individually.

`schedule_function()` records the callback in `wps#cron-events` and queues a
single event. Its variadic array arguments remain separate callback arguments.
WordPress removes the due event before invoking the callback; a callback may
queue its next batch. `run_event()` queues an immediate single event while
preserving the existing recurring event.

During initialization, registered callback events use WordPress's
`md5(serialize($args))` signature. Signature correction preserves timestamps,
event payloads and unrelated hooks; an already canonical entry takes precedence
over a duplicate. The cron option is written only when a signature changes,
and a failed write is reported in the cron error log.

## Regression check

From the CMS root, run `php -n mini-test/cron-actions.php` with PHP 7.4 or newer.
The check loads the adjacent installation's WordPress Cron API and the framework
classes, using isolated option and hook fakes. It needs no database or network.
