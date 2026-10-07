# Voice Gateway — AI and vendor-key boundary (I-32, G18#13)

Status: **documented exception, not resolved here.** Recorded so nobody reads "Voice holds no
model key" as covering the Voice Gateway.

## What is true of this repository

- Every model call Voice's PHP makes goes to AI Pulse (`server-php/src/Ai/PulseAiClient.php`):
  call summaries today. No provider host, SDK or model key exists in `server-php/` or `web/`, and
  `tests/integration.php` group 25 fails if one appears.
- Every Pulse call carries Voice's own gateway key, `PULSE_SERVICE_KEY` (minted on Pulse with
  `php spark pulse:gateway-key mint voice`), as `X-Pulse-Service-Key`, beside the signed-in user's
  session when there is one. With no user session (another product's backend calling Voice) the
  key goes alone. The estate-wide `CONSOLE_SERVICE_KEY` is no longer a fallback; Pulse accepts a
  session without the product key only until 2026-11-15.

## What is not in this repository

The **Voice Gateway** is a separate service (no repository among the audited ones). It owns SIP,
WebRTC, RTP, streaming speech recognition and synthesis, barge-in and playback, and the live AI
conversation runtime of AI voice agents and `ai_conversation` campaigns. Per pulse-aicountly
`docs/PULSE_LAUNCH.md` (speech and stored-recording transcription stay in the Gateway), the
Gateway **keeps its own speech/AI vendor keys outside Pulse and Console** for now.

Consequences, stated plainly:

| Topic | State |
|---|---|
| Vendor keys | Held by the Gateway, not by Console; not rotated or audited through Console. |
| Usage and budgets | Live-call AI and speech usage does not appear in Pulse/Console per feature. |
| Tool execution | `AiClient::TOOLS` (`lookup_contact`, `create_booking`, …) is a catalogue only in this repo; where those run, and as whom, is the Gateway's. Any Contacts read it makes must use a Contacts delegation grant (contract v1 §3.9) — never a stored user session. |
| What Voice sends the Gateway | `VOICE_GATEWAY_URL` / `VOICE_GATEWAY_KEY` (one key per deployment); call control only. Voice never hands the Gateway a user's `ses_key`. |

## The boundary Voice enforces

- Voice never passes a model key, a user session or a Contacts token to the Gateway.
- Provider callbacks from the Gateway carry no Aicountly identity; they may only move Voice-owned
  call state (`WebhooksController`), and never call another product.

## Open items for the owners (not authorised here)

1. Gateway owners: an inventory of the vendor keys it holds, and a plan to move speech and
   transcription behind Pulse endpoints, or an approved, written exception.
2. Pulse: mint Voice's own gateway key (`voice`) on production and sandbox Pulse and set it as
   `PULSE_SERVICE_KEY` in each `api/.env` before 2026-11-15. (The code no longer reads
   `CONSOLE_SERVICE_KEY` for Pulse.)
3. Runtime check (needs Gateway access): the deployed Gateway's configuration matches this page.
