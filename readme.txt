=== Kingacademic SMTP & Email Log ===
Contributors: kingacademic
Tags: smtp, email, mail, email log, wp_mail
Requires at least: 5.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Configure SMTP, send WordPress email from the dashboard, and keep privacy-conscious delivery logs.

Copyright 2026 Kingacademic. Official website: https://www.kingacademic.com/

== Features ==
* Configure SMTP host, port, encryption, authentication, username and password.
* Configure From Email and From Name.
* Send email directly from the WordPress admin dashboard.
* Log sent/failed status, timestamp, recipient and subject.
* Error messages are redacted defensively before logging.
* SMTP passwords are never displayed in the Email Log.
* SMTP passwords are never re-rendered into the settings form.
* Email message bodies are not stored in the log.
* Configurable automatic log retention.
* Individual log deletion and Delete All Logs.

== Privacy & Security ==
The email log intentionally does not store SMTP passwords, email message bodies, authentication headers or attachment contents. Known credentials and common password, token, API-key and Authorization patterns are redacted from error messages before storage. Only WordPress administrators with the manage_options capability can view or delete logs.

The SMTP password is stored as a WordPress option because it must be available to WordPress at send time. Protect database access and use normal WordPress/hosting security controls.

Recipient addresses and subject lines are stored as log metadata because they are required for the email-history feature.

== Installation ==
1. Upload the plugin ZIP in Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Open SMTP & Email Log in the WordPress admin menu.
4. Enter your SMTP settings and save.
5. Use Send Email to test delivery.
6. Use Email Log to review delivery status.

== Changelog ==
= 1.0.0 =
* Initial release.
