# Kingacademic SMTP & Email Log

A free, privacy-conscious WordPress plugin for configuring SMTP, sending email from the dashboard, and reviewing delivery logs without exposing SMTP passwords or message bodies.

## Features

- Configure SMTP host, port, TLS, SSL, or no encryption.
- Use SMTP authentication with a username and password.
- Set the From Email and From Name, with an option to force them site-wide.
- Send a test email directly from the WordPress dashboard.
- Review the date, recipient, subject, and Sent/Failed status for each email.
- Store only a redacted error message when sending fails.
- Delete individual log entries or clear the full log.
- Keep logs for 7, 30, 90, 180, or 365 days, or until manually deleted.

## Privacy and security

The Email Log does **not** store SMTP passwords, email message bodies, authentication headers, or attachment contents. Known credentials and common password, token, API-key, and Authorization patterns are removed from error messages before they are stored.

The saved SMTP password is never rendered back into the settings form. WordPress still needs the password at send time, so it is stored in the WordPress options database. Protect database access and follow normal WordPress and hosting security practices.

Recipient addresses and subject lines are stored as log metadata because they are required for the plugin's email-history feature. Only WordPress administrators with the `manage_options` capability can view or delete logs.

## Installation

1. Download the latest release ZIP.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP, install it, and activate **Kingacademic SMTP & Email Log**.
4. Open **SMTP & Email Log** in the WordPress dashboard and enter your SMTP settings.

## Important delivery note

“Sent” means WordPress and PHPMailer accepted the email for sending. It does not guarantee that the recipient's mail server delivered it to the inbox.

## Author

Created by [Kingacademic](https://www.kingacademic.com/).

## License

Copyright © 2026 Kingacademic. Licensed under GPL-2.0-or-later.
