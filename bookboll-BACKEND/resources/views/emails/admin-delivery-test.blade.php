@include('emails.header')

<div class="content">
    <h1>Delivery test</h1>

    <div style="white-space: pre-wrap; margin: 0 0 20px; font-size: 15px; line-height: 1.6; color: #334155;">{{ $bodyText }}</div>

    <table role="presentation" cellpadding="0" cellspacing="0" style="width: 100%; border-top: 1px solid #e5e7eb; padding-top: 12px; font-size: 12.5px; color: #64748b;">
        <tr>
            <td style="padding: 4px 0; width: 120px;">Sent by</td>
            <td style="padding: 4px 0; color: #0f172a;">{{ $sentBy }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 0;">Mailer</td>
            <td style="padding: 4px 0; color: #0f172a;">{{ $mailerLabel }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 0;">Environment</td>
            <td style="padding: 4px 0; color: #0f172a;">{{ $environment }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 0;">Sent at</td>
            <td style="padding: 4px 0; color: #0f172a;">{{ $sentAt->format('M j, Y · g:i:s A') }} ({{ $sentAt->timezoneName }})</td>
        </tr>
    </table>

    <p style="margin-top: 16px; font-size: 12px; color: #94a3b8;">This message was sent from the Bookboll admin panel to check outbound email. No action is needed.</p>
</div>

@include('emails.footer')
