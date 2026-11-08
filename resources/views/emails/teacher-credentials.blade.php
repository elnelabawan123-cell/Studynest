<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $context === 'reset' ? 'Password Reset' : 'Account Created' }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f6f8; font-family: Arial, Helvetica, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8; padding:32px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="background:#1e3a8a; padding:24px 32px;">
                            <h1 style="margin:0; font-size:20px; color:#ffffff;">StudyNest</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="font-size:16px; color:#111827; margin-top:0;">
                                Hi {{ $name }},
                            </p>

                            @if ($context === 'reset')
                                <p style="font-size:14px; color:#374151; line-height:1.6;">
                                    Your StudyNest password has been reset by an administrator. Your new login details are below.
                                </p>
                            @else
                                <p style="font-size:14px; color:#374151; line-height:1.6;">
                                    A StudyNest account has been created for you. Please find your login details below.
                                </p>
                            @endif

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0; border:1px solid #e5e7eb; border-radius:6px;">
                                <tr>
                                    <td style="padding:12px 16px; border-bottom:1px solid #e5e7eb; font-size:13px; color:#6b7280; width:160px;">Role</td>
                                    <td style="padding:12px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; color:#111827; font-weight:bold;">Teacher</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; border-bottom:1px solid #e5e7eb; font-size:13px; color:#6b7280;">Username</td>
                                    <td style="padding:12px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; color:#111827; font-weight:bold;">{{ $username }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; font-size:13px; color:#6b7280;">Temporary Password</td>
                                    <td style="padding:12px 16px; font-size:14px; color:#111827; font-weight:bold; font-family: monospace;">{{ $password }}</td>
                                </tr>
                            </table>

                            <p style="font-size:14px; color:#374151; line-height:1.6; margin-bottom:8px;"><strong>Login Instructions</strong></p>
                            <ol style="font-size:14px; color:#374151; line-height:1.8; padding-left:20px; margin-top:0;">
                                <li>Go to <a href="{{ $loginUrl }}" style="color:#1e3a8a;">{{ $loginUrl }}</a></li>
                                <li>Sign in using the username and temporary password above.</li>
                                <li>You will be required to set a new password on your first login.</li>
                            </ol>

                            <p style="font-size:12px; color:#9ca3af; margin-top:24px;">
                                For security, do not share this password. If you did not expect this email, please contact your school administrator.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f9fafb; padding:16px 32px; font-size:12px; color:#9ca3af;">
                            &copy; {{ date('Y') }} StudyNest. This is an automated message, please do not reply.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>