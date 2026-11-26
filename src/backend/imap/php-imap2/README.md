# PHP IMAP Drop in Replacement for PHP 8.4

A drop in PHP replacement for the imap_ functions removed from PHP 8.4. If imap_ functions are detected, then the built in functions are used. So you can install this on PHP 8.2 or 8.3 and when you upgrade to PHP 8.4, it will continue to work.

## Usage

```php
include 'vendor/phpfui/php-imap2/src/Imap2/IMAPStubs.php
// continue to use imap_ functions. Example:
//$mbh = \imap_open($server, $username, $token, OP_XOAUTH2);
```

## This package is based on arbor-education/php-imap2 and jaavanile/php-imap2

It has been updated to modern PHP 8 standards and simplified. Since this a just a wrapper around the imap_ functions, it can easily be updated to a better IMAP library if needed.

## Unimplemented functions

The following functions were not implemented in the original source and will be undefined:

* function imap_mail(string $to, string $subject, string $message, ?string $additional_headers = null, ?string $cc = null, ?string $bcc = null, ?string $return_path = null) : bool
* function imap_mail_compose(array $envelope, array $bodies) : string|false
* function imap_mime_header_decode(string $string) : array
* function imap_set_quota(IMAP\Connection $imap,string $quota_root): array|false
* function imap_listsubscribed(\IMAP\Connection $imap, string $reference, string $pattern) : array|false
* function imap_lsub(\IMAP\Connection $imap, string $reference, string $pattern) : array|false

The following functions have no effect:

* function imap_mutf7_to_utf8(string $string) : string

## Testing

Due to the complexities of setting up and running an actual IMAP server in GitHub Actions, test is confined to testing on live sites.

Anyone interested in contributing tests should look into updating the original tests in javanile/php-imap2 and get them to run under GitHub Actions.	PRs welcome.

The following functions seem to work on live sites:

* imap_close()
* imap_delete()
* imap_errors()
* imap_num_msg()
* imap_open()
* imap_savebody()
