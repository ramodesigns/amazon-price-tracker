# Handling credentials in this repo — read before touching `.env`

This file exists because a real credential leaked into a Claude Code conversation
transcript on 2026-07-14. Read this before writing any script, test, or command
that touches `.env`, `APT_TEST_*` env vars, or any Amazon PA-API / Creators API
credential. This applies equally to human contributors and to AI coding agents
(Claude Code, vexp, or anything else) working in this repository.

## The rule

**Nothing in `.env` - or any value derived from it (access tokens, signed
headers, Authorization strings) - may ever be printed, logged, echoed, or
otherwise made to appear in a terminal, tool output, CI log, or conversation
transcript.** Not even partially, not even "just to confirm it loaded." If you
need to confirm a credential is present, check `!empty($value)` /
`getenv(...) !== false` and print a boolean or a masked form (see
`Encryption::mask()` for the pattern this codebase already uses for
`access_key`), never the value itself.

## What actually happened (2026-07-14)

A one-off PHP audit script called `curl_close()` (a no-op since PHP 8.0,
deprecated as of 8.5) inside a small `curl_post()` helper. On this machine,
**Xdebug is installed** (see `docs/testing-roadmap.md`'s mutation-testing
section) with a mode that enhances PHP's error handler: when Xdebug is
active, *any* warning, notice, or deprecation - not just fatal errors -
gets rendered with a full stack trace **that includes the literal
arguments passed to every function in the call stack**. The deprecation
notice from `curl_close()` therefore printed the full argument list of
`curl_post()`, which included the request body (containing the OAuth
`client_secret`) and the `Authorization: Bearer <token>` header, straight
into the tool output.

Nothing about the script *looked* dangerous - there was no `var_dump`, no
`print_r`, no logging line. The leak came from an unrelated, seemingly
harmless deprecation notice interacting with Xdebug's default behavior.
That's the trap: **any warning/notice/deprecation triggered while a secret
is live anywhere in the current call stack can leak it, via Xdebug's stack
trace, even in code that never explicitly prints anything.**

## Rules for any script that handles real credentials

1. **Assume Xdebug is installed and will dump function arguments on any
   diagnostic message**, not just uncaught exceptions. Before running a
   script that has a credential in scope, disable Xdebug's argument capture
   for that invocation:
   ```
   php -d xdebug.mode=off script.php
   ```
   or `XDEBUG_MODE=off php script.php`. Prefer this for every throwaway
   script that touches `.env`, not just ones you think might warn.
2. **Never call deprecated/removed-behavior functions** in scripts that
   handle secrets (e.g. `curl_close()` on PHP 8+) - if a function is
   deprecated, drop the call rather than "leave it in, it's harmless."
   It is not harmless here.
3. **Never enable `CURLOPT_VERBOSE`** or any HTTP client debug/trace mode
   against these APIs - verbose curl output includes full request headers.
4. **Never `error_log()`, `var_dump()`, `print_r()`, or `fwrite(STDERR, ...)`
   a full request payload, headers array, or response array without
   checking first that it contains no `Authorization` header, no
   `client_secret`/`secret_key`/`access_token` field.** Log operation names,
   URLs, and status codes instead (this is what
   `Amazon_Creators_API`/`Amazon_API`'s own `WP_DEBUG` logging already does
   correctly - match that pattern, don't regress it).
5. **Never `cat`, `grep -v '^#'`, `head`, or otherwise dump the contents of
   `.env`** into a shell command whose output is captured by a tool, CI job,
   or AI assistant. If you need a specific value inside a script, read the
   file *inside that script* and use the value in memory - never pass it
   through a shell command that echoes it back.
6. If you're an AI agent and need to prove a live API call works: write a
   script that reads `.env` internally, makes the call, and prints only the
   **response body** (product data, status codes) - never the request
   headers, the payload if it contains credentials, or anything from the
   token response. That's genuinely not secret and safe to show the user as
   proof the integration works.
7. **If a credential is ever suspected to have appeared in a transcript,
   terminal scrollback, log file, or anywhere outside `.env` itself, treat
   it as compromised and rotate it.** Don't assess whether it was "probably
   fine" - just rotate. For Creators API: Associates Central → Tools →
   Creators API → regenerate the credential secret. For PA-API: regenerate
   the secret key. This is cheap; a lingering exposed secret is not.

## Also still true (unrelated to the incident above, but worth restating)

- `.env` is gitignored. Never remove it from `.gitignore`, never `git add -f`
  it, never commit real credentials anywhere - including inside test files,
  fixtures, or example payloads in `docs/`.
- `.env.example` should only ever contain empty/placeholder values.
- Real credentials belong in `.env` (local dev/testing) or the plugin's own
  encrypted `apt_user_settings` DB storage (real users, via `Encryption`) -
  never hardcoded in PHP, never in a commit message, never in a GitHub issue
  or PR description.
