{{-- Deliberately plain, same reasoning as the payslip mail: a message carrying
     a password should read like a note from the centre, not a template, or the
     spam filter and the recipient both treat it as phishing. --}}
<div style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #26323d; line-height: 1.55;">
    <p style="margin: 0 0 14px;">Hi {{ $name }},</p>

    <p style="margin: 0 0 14px;">An account has been created for you on {{ config('app.name') }}. Sign in with the temporary password below — you will be asked to choose your own password straight away.</p>

    <table cellpadding="0" cellspacing="0" style="margin: 0 0 18px; border-collapse: collapse;">
        <tr>
            <td style="padding: 6px 16px 6px 0; color: #6b7a88;">Email</td>
            <td style="padding: 6px 0; font-weight: bold;">{{ $email }}</td>
        </tr>
        <tr>
            <td style="padding: 6px 16px 6px 0; color: #6b7a88;">Temporary password</td>
            <td style="padding: 6px 0;">
                <span style="display: inline-block; font-family: Consolas, Menlo, monospace; font-size: 16px; letter-spacing: 1px; font-weight: bold; background: #f1f5f9; border: 1px solid #dbe3ea; border-radius: 6px; padding: 6px 12px;">{{ $password }}</span>
            </td>
        </tr>
    </table>

    <p style="margin: 0 0 14px;">
        <a href="{{ $loginUrl }}" style="display: inline-block; background: #4f46e5; color: #ffffff; text-decoration: none; font-weight: bold; border-radius: 8px; padding: 11px 20px;">Sign in</a>
    </p>

    <p style="margin: 0 0 14px; color: #6b7a88;">Or paste this into your browser: {{ $loginUrl }}</p>

    <p style="margin: 0 0 14px;">This password only works until you set your own. If you were not expecting this email, please tell the centre director so the account can be closed.</p>

    <p style="margin: 24px 0 0; font-size: 12px; color: #6b7a88;">
        {{ config('app.name') }}
    </p>
</div>
