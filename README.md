Messages dispatcher
===================

Simple PHP-based electronic mailing list with messengers integration
(Viber, Telegram).


Requirements
------------

1. PHP interpreter, tested with version 7.0,  should work with earlier versions.
2. PHP modules: `json`, `imap`, `mbstring`, `zip`.
3. Library `PHPMailer` (`libphp-phpmailer` package in debian-based distros);
   only needed when SMTP is used, see below.


Basic usage
-----------

1. Create `config.json` (see below, example in `example.config.json`);
2. Run `php do_dispatch.php`.
3. If everything ok, add `php do_dispatch.php` to cron or other scheduler.

Alternatively, there are some top-level routines in `dispatcher.php`
that can be called from external PHP script, e.g. from WP Form handler (hook).


Configuring
-----------

`config.json` must contain object, each key corresponds to dispatcher
instance. Available options to be configured:

1. `disabled`: boolean (default `false`), means that this dispatcher must
    be skipped.
2. `email`: mailbox address for emails, both incoming and outcoming.
3. `password`: mailbox password to be used for authentication (with both
    SMTP and IMAP connections, see below).
4. `subject`: arbitrary string to be added to messages being dispatched;
5. `url-prefix`: host and path to location of dispatcher files, needed
    to deduce correct webhook URL and sharing attachments;
6. `attachments-dir`: subdirectory name where attachments will be stored;
7. `ftp`: (optional object) specifies FTP-connection for uploading
    attachments to remote server;
    
        7.1 `ftp.dir`: remote directory where to upload attachments;
        7.2 `ftp.host`: FTP-server hostname;
        7.3 `ftp.username`: FTP-server login username;
        7.4 `ftp.password`: FTP-server login password;
    
8. `smtp`: (optional object) specifies SMTP-connection for sending
    emails (if it is abscent, `imap_mail()` will be used);
    
        8.1 `smtp.host`: SMTP-server hostname;
        8.2 `smtp.port`: SMTP-server port;
        8.3 `smtp.ssl`: (optional object) options for PHPMailer when using
            ENCRYPTION_SMTPS encryption mode;
        8.4 `smtp.tls`: (optional object) options for PHPMailer when using
            ENCRYPTION_STARTTLS encryption mode;
        
9. `sources`: list of legitimate addresses, emails from them will be
    dispatched (broadcasted) to all subscribers;
10. `imap`: string for configuring IMAP connection, see `imap_open()`
    PHP documentation;
11. `viber-bot`: (optional) auth token for Viber Bot API;
12. `viber-chat`: (optional) auth token for Viber Channel Post API;
13. `tg-bot`: (optional) auth token for Telegram Bot API;
14. `viber_bot_welcome_message`: (optional) message that will be sent
    when conversation started with new unsubscribed user.


Other settings
--------------

Contained in first few lines of `dispatcher.php`:

1. `CONFIG_PATH`: (default "config.json") path to configuration file;
2. `STATE_PATH`: (default "state.json") path to state file;
3. `LOG_PATH`: (default "dispatch.log") path to logfile;
4. `LOG_LEVEL`: (default 2) level of logging information;


Viber
-----

For proper setting webhooks that are strictly needed for use of Viber API,
`viber_webhook.php` needs to be exposed (available by `{url-prefix}/viber_webhook.php`).
Then add tokens to `config.json` and run `viber_setup.php`
(manually or via remote browser) or just `do_dispatch.php`.


Attachments
-----------

For posting of attachments to Viber and Telegram, `attachments-dir` needs
to be exposed (available by `{url-prefix}/{attachments-dir}/{filename}`.


Security precautions
--------------------

1. **Strongly** restrict access to `CONFIG_PATH` since it
    contains secret information such as email/ftp password and messengers
    tokens.
2. Also strongly restrict access to `LOG_PATH` since it may contain
    auth tokens and/or passwords (with some log levels).
2. Restrict access to `STATE_PATH`, since it may reveal potentially
    sensitive list of contacts (emails and messengers ids).
3. Forbid server-side execution of attachments.
4. Also client-side execution of attachments can be forbidden via
    restricting access to extensions like `htm`, `html`, `css`, `js` in
    attachments folder; though it will be always possible to construct
    some special file of complex format (e.g. `pdf`) that exploits some
    vulnerability, so *just* be sure that all `sources`-addresses are not
    compromised.

Example of `.htaccess` files are in source tree (root and attachments folder).
