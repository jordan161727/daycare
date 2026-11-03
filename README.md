<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Email (Mailtrap)

AngelNest sends the "temporary password" email (staff profile → **Send a new temporary password**) through the Mailtrap HTTP API. The code is in `app/Services/Mail/`, and the wording is in `resources/mail/temp-password-email.html` and `.txt`. The new-teacher welcome email still goes over the SMTP settings (`MAIL_MAILER` and the related settings).

**Getting the token and inbox ID**

1. Sign in at [mailtrap.io](https://mailtrap.io).
2. **API token:** Settings → API Tokens → Add Token. Give it access to the Sandbox inbox and, for live mode, to the sending domain. Copy it into `MAILTRAP_API_TOKEN`.
3. **Inbox ID:** Sandboxes → open the inbox. The number in the page address (`.../inboxes/1234567/messages`) is the ID. Put it in `MAILTRAP_INBOX_ID`.
4. Run `php artisan config:clear` (or `config:cache` on the server) after editing `.env`.

```
MAILTRAP_API_TOKEN=your-token
MAILTRAP_INBOX_ID=1234567
MAIL_MODE=sandbox
MAIL_FROM=no-reply@angelnest.test
MAIL_FROM_NAME=AngelNest
```

If the token is missing, or sandbox mode has no inbox ID, the button refuses with "Email is not set up on this site yet". Nothing changes, and the full reason is in `storage/logs/laravel.log`.

**Sandbox vs live**

- `MAIL_MODE=sandbox` (the default) posts every email to the test inbox. Nobody real receives anything, so this is the right mode for local and staging copies with real staff addresses in them.
- `MAIL_MODE=live` delivers for real. First add and verify the centre's sending domain in Mailtrap (Sending Domains → DNS records). Then set `MAIL_FROM` to an address on that domain, because Mailtrap refuses any other From address. `MAILTRAP_INBOX_ID` is ignored in live mode.

**Checking emails**

- Sandbox: Mailtrap → Sandboxes → your inbox. Each email shows with its HTML, text, and headers.
- Live: Mailtrap → Email Logs. Filter by category `temp-password`.
- AngelNest's own log records each send as `[mail] temp-password sent` (or `refused` / `not sent`) with the masked address and HTTP status. The email body is never logged, because it contains the password.

A temporary password expires after `DAYCARE_TEMP_PASSWORD_HOURS` (72 by default). After that the sign-in form refuses it, and the director sends another.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
