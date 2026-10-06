# Network Internet Support Scheduler

A responsive PHP 8+/MySQL 8+ application for scheduling connectivity support, assigning technicians, tracking equipment, and reporting event progress.

## Quick start (XAMPP)

1. Copy this folder to `C:\xampp\htdocs\network-support`.
2. Create a MySQL database by importing `database/schema.sql` in phpMyAdmin.
3. Edit the database credentials in `config.php` if needed.
4. Open `http://localhost/network-support`.

Demo login: `admin` / `password` (change it immediately after installation).

## Included

- Session authentication, CSRF validation, prepared statements, password hashing, and role gates.
- Separate event and assignment state workflows.
- Dashboards for Super Administrator, Administrator, and Network Technician.
- Event creation, technician assignment with overlap warnings, equipment availability check, and assignment completion notes.
- Notifications, activity logging, filters, and a normalized MySQL schema with seed data.

## SMS assignment notifications (starter)

- Assignment creation calls `sms_assignment_reminder()` for each newly assigned technician. It sends nothing while SMS is disabled.
- Add a technician's mobile number under **Administration → Users**.
- `sms.php` has a disabled, configurable Smart HTTP adapter. Add the endpoint, authentication, and payload field names from your Smart API guide to `D:\xampp\private\network_support\sms_config.php`; enable it only after those match the guide. The generic adapter supports JSON or form requests, not SOAP.
- The credentials file is outside `htdocs`, with its file ACL limited to the project owner, SYSTEM, and Administrators. Never put real API credentials in the project or commit them. Philippine `09...` and `+639...` mobile numbers are normalized before sending.
- This starter sends an SMS immediately when a new assignment is created. Timed reminders need a chosen lead time and a scheduled worker/cron task.
- For an existing database, apply `database/migration_technician_phone_number.sql`; it has already been applied to the local XAMPP database for this project.

## Floating local AI assistant (optional)

- The signed-in assistant has Operations, Dispatch, Reports, and GIS modes. It uses a local Ollama server only; the PHP endpoint rejects non-loopback URLs, so prompts and system data cannot be sent to a cloud provider.
- Local settings are stored outside `htdocs` in `D:\xampp\private\network_support\ai_config.php`. The configured model is `qwen3:1.7b` and the default Ollama API is `http://127.0.0.1:11434`.
- After installing Ollama on the same computer as Apache, run `ollama pull qwen3:1.7b` and keep Ollama running. The app does not need a cloud API key. Model files take local disk space; the Qwen3 1.7B listing is about 1.4 GB.
- The assistant sends the user's question and aggregate event/assignment counts to that local model. It does not send staff names, phone numbers, event notes, or individual event records. Chat history stays in the browser page and is not stored in the application database.
- This AI setup is separate from LGUNET SSO; SSO credentials are not used by the local model.
