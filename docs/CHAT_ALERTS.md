# Marketplace chat alerts — 16 November 2026

## Product behavior

- Community and event chats support selecting accepted participants with `@`, using pointer or keyboard. Tags are highlighted. Ordinary text containing a name is not a notification tag. The server validates each participant and its exact name and text range. Names/images/IDs are the only participant-search fields.
- The existing chat write permissions remain authoritative. Pending members and outsiders cannot search participants or send mentions. Deleted messages, inactive accounts, cancelled events and departed recipients do not receive new email/WhatsApp alerts.
- **Superadmin → Settings → Automated notifications → Chat & mention alerts** controls direct messages, group messages and mentions independently for email and WhatsApp. Providers and ordinary admins cannot use this API. Generic settings writes cannot change its reserved key.
- Customer **My account → Chat alerts** has email (default on) and WhatsApp (default off) preferences. This is separate from marketing consent. WhatsApp requires explicit chat opt-in and a valid account phone number.
- Each message creates durable MySQL alert rows in the same transaction as the message. Only opted-in channels enabled at send time are queued. Settings, membership and read state are checked again before delivery. Enabling a switch does not replay history.
- Wait two minutes, then combine eligible unread messages into one alert per recipient/conversation/channel. Cooldown is 15 minutes; pending messages are reconsidered afterward. A mention replaces the generic group classification for that recipient, avoiding duplicate alerts. Alerts older than 24 hours expire.
- Emails and WhatsApp contain a chat link, not private message contents. The destination still requires sign-in and membership. Web group/event reads acknowledge a specific message ID so a newer message is not accidentally marked read. The existing community read endpoint also updates the alert cursor for older mobile clients. New mobile/event clients should use the precise read endpoint when opening chat.

## Deploy with MySQL

1. Deploy backend plus marketplace and admin frontends. Run `php artisan migrate --force`: migration `2026_11_16_000001_add_chat_mentions_and_alerts` adds `customer_messages.mentions`, `chat_reads`, and `chat_alerts`. No historical chats are rewritten or notified.
2. Run the normal Laravel scheduler every minute. `chat:send-alerts` is registered in `routes/console.php`. No queue worker is required for this command. Railway's isolated workspace loop also invokes it, but `preview.enabled` forces simulated outcomes before any email/WhatsApp call.
3. Verify production mail configuration and `FRONTEND_URL` / `config('app.frontend_url')` target the correct marketplace. Test only with authorized test recipients before enabling production delivery.
4. Connect Bookboll's platform WhatsApp sender. Set `WHATSAPP_CHAT_TEMPLATE` to an approved Meta template and `WHATSAPP_CHAT_LANGUAGE` (default `en_US`). The body requires three parameters in this order: alert type (`unread messages` or `an unread mention`), conversation title, full chat URL. Example: `You have {{1}} in {{2}} on Bookboll. Open your chat: {{3}}`. Do not send free-form outbound messages instead. Approval is performed by Meta; adding a template name alone is not approval. Missing setup defers delivery, bounded by the 24-hour expiry.
5. Superadmin settings show whether the sender/template are configured, whether this is preview, and any uncertain deliveries. `needs_review` means a send outcome is uncertain; inspect the mail/WhatsApp delivery logs before any manual intervention. There is deliberately no automatic retry of a potentially delivered alert. Interrupted `sending` rows become `needs_review` after five minutes.

## API contract

- `GET /api/customer/messages/participants?key=community__UUID&q=Name` (also `meet__UUID`): accepted participants only, at most 20, no contact data.
- `POST /api/customer/messages/group`: existing `key`, `body`, plus optional `mentions: [{user_id: UUID, start: 3, length: 7}]`. `start` and `length` are **UTF-16 code units**, matching JavaScript selection offsets; e.g. `🏸 @Player` starts at 3. Maximum 20 mentions. The server supplies the canonical `label` in returned messages.
- `POST /api/customer/messages/group/read`: `key`, `through` (last displayed message UUID). Authorizes group membership and never moves the cursor backward. Clients should call only while the chat is visible.
- `GET/PUT /api/customer/messages/preferences`: `{email: boolean, whatsapp: boolean}`.
- `GET/PUT /api/admin/chat-notifications`: superadmin only; `{direct:{email,whatsapp}, group:{email,whatsapp}, mention:{email,whatsapp}}`. Responses also include delivery summary and setup status, never sender credentials.

## Required checks

CI runs `ChatAlertsTest.php`, existing `CommunityPermissionsTest.php`, `chatMentions.test.js` and `calendarLayout.test.js`. Run the feature tests only against a disposable MySQL database. These cover unauthorized/pending tags, malformed and emoji offsets, event membership/read state, latest-page ordering, batching, cooldown, idempotency, preview isolation, opt-out, settings authorization, fake WhatsApp template delivery and uncertain-send handling.

Real email/Meta delivery remains a deployment integration check; mocked tests and Railway's simulated records do not prove external delivery. The mobile application is not present in this repository: it must send/consume the optional mentions array and acknowledge reads using the contract above. Existing mobile text-only sends remain compatible.
