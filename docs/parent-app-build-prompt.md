# Prompt: build the AngelNest parent app (React Native)

Copy everything below the line into your AI coding assistant (Claude Code or similar), opened in a **new, empty folder** for the app. Before you start, copy these three files from the AngelNest repo into that folder's `docs/` so the assistant can read them:

- `docs/parent-app-api.md`: the API reference
- `docs/parent-app.postman_collection.json`: every endpoint, with examples
- `docs/parent-app-postman-guide.md`: how sign-in and messaging behave, step by step

---

## Prompt

You are building **AngelNest**, the parent app for Little Angels Day Care Center. Parents use it to read and send messages to the centre. Build it with **React Native + Expo (TypeScript)**.

The backend already exists (Laravel). Don't change it, and don't invent endpoints. The API contract is in `docs/parent-app-api.md` and `docs/parent-app.postman_collection.json`. Read both before writing code, and treat them as the source of truth wherever this prompt and those files disagree. `docs/parent-app-postman-guide.md` shows the sign-in and messaging flow end to end.

### What the app does

1. **Sign-in by text code, with no password.**
   - The parent enters their mobile number and gets a 6-digit code by text.
   - They type the code and the app receives a **Bearer token** (valid 90 days).
   - Store the token in `expo-secure-store`.
2. **Inbox:** the parent's conversations, newest first, each with:
   - a badge: **Room · <classroom>**, **Office** or **Announcement**
   - the last message preview
   - the time
   - an unread count
3. **Conversation:**
   - Messages oldest to newest: the parent's own (`from.is_me`) on the right, the centre's on the left, with the sender name and time under each.
   - Photos inline, and a link for videos.
   - A reply box with **Send** and a **photo** button.
   - Announcements have **no** reply box. Show *"Announcements are one-way"* and a **Message the office** button instead.
4. **Start a conversation:** from the children list, **Message the room** / **Message the office** for each child.
5. **Push notifications:**
   - Register the Expo push token after sign-in.
   - Tapping a notification opens that conversation (`data.thread_id`).
6. **Settings:** the parent's name and children, the centre's phone lines, and **Sign out**.

### API summary

Full details are in `docs/parent-app-api.md`. Base URL: `{API_BASE}/api/parent`. Every request sends `Accept: application/json`; signed-in requests also send `Authorization: Bearer <token>`.

| Method | Path | Body / query | Use |
|---|---|---|---|
| GET | `/ping` | | Connection and sign-in check: `{ connected, signed_in, parent }` |
| POST | `/login/start` | `{ phone }` | Text a code. Always `200 { sent: true }` |
| POST | `/login/verify` | `{ phone, code, device_name }` | → `{ token, parent }` |
| POST | `/logout` | `{}` | Cancel the token |
| GET | `/me` | | Parent, children, centre lines |
| GET | `/threads` | | Inbox |
| GET | `/threads/{id}` | `?limit=` · `?after_id=` · `?before_id=` | Conversation page, oldest first |
| POST | `/threads/{id}/messages` | `{ body?, media?: [{ path, kind }] }` | Send → `201 { message }` |
| POST | `/threads/{id}/read` | `{ last_message_id? }` | Mark read |
| POST | `/children/{childId}/threads` | `{ kind: "room" \| "office" }` | Find or open a conversation |
| POST | `/uploads` | multipart `file` | → `{ path, kind, url }`, then send with `media` |
| POST / DELETE | `/devices` | `{ push_token, platform }` | Register or forget push |

Errors are always JSON:
- `401`: sign out and show the sign-in screen.
- `404`: not this parent's.
- `422`: `{ message, errors: { field: [..] } }`. Show the first error under its field.
- `429`: "Too many tries — wait a minute".

### Behaviour rules
- On launch: no token → Sign-in. With a token, call `GET /me`; on `401` clear the token and go to Sign-in.
- **Phone input:** accept any format (`(585) 555-0134`, `5855550134`) and send it as typed. The server matches the last 10 digits.
- **Code screen:**
  - 6-digit numeric input with autofill (`textContentType="oneTimeCode"`, `autoComplete="sms-otp"`).
  - **Resend** button, disabled for 30 s.
  - On `422` show *"That code is wrong or has expired"*.
- **Checking for new messages:**
  - Inbox every 30 s while visible.
  - Open conversation every 10 s using `after_id` = the last message id.
  - Also refresh on app foreground and when a push arrives.
  - Use `@tanstack/react-query` with `refetchInterval`.
- **Read marks:** call `POST /threads/{id}/read` when a conversation opens and when new messages arrive while it's open.
- **Sending:**
  - Show the message right away, using the `201` answer.
  - Disable Send while it's in flight.
  - Keep the text if sending fails.
- **Scrolling up:** `before_id` = the oldest loaded id, `limit=30`.
- **Photos:**
  - `expo-image-picker` → `POST /uploads` as multipart → send the returned `path` and `kind` in `media`.
  - Up to 4 per message.
  - `media[].url` is a signed link; load it directly, without the token.
- **Times:** `sent_at` is ISO 8601 with an offset. Show "9:49 AM" today, "Yesterday", or "Nov 6" otherwise.
- **Sign out:** `DELETE /devices` with the push token → `POST /logout` → clear storage → Sign-in.
- Never log or display the token.

### Tech
- Expo SDK (latest), TypeScript, **expo-router** (file-based routes).
- `@tanstack/react-query` for server state.
- `expo-secure-store`, `expo-notifications`, `expo-device`, `expo-image-picker`, `expo-constants`.
- `API_BASE` from `app.config.ts` → `extra.apiBase`, read from `EXPO_PUBLIC_API_BASE`.
- No other state library. Keep components small, with a single `src/api.ts` client: one `api<T>()` wrapper that adds headers, parses JSON and throws an `ApiError` with `status` and `errors`.

Suggested structure:
```
app/
  _layout.tsx              # QueryClient, auth gate, notification listeners
  (auth)/phone.tsx
  (auth)/code.tsx
  (app)/index.tsx          # Inbox
  (app)/thread/[id].tsx    # Conversation
  (app)/children.tsx       # Children + start a conversation
  (app)/settings.tsx
src/
  api.ts  types.ts  auth.tsx  push.ts  format.ts
  components/ ThreadRow.tsx  MessageBubble.tsx  Composer.tsx  Badge.tsx
```

### Design (Little Angels design system)

| Token | Value |
|---|---|
| Font | Inter (`@expo-google-fonts/inter`) |
| Page background | `#f6f7fb` |
| Card | `#ffffff` |
| Ink | `#1c2130` |
| Muted ink | `#5f6676` |
| Border | `#e7e9f0` |
| Accent | `#1b5e91` |
| Accent soft | `#dcebfa` |
| Danger | `#be123c` |

Badges:

| Badge | Background | Text |
|---|---|---|
| Room | `#e3f5ec` | `#146c35` |
| Office | `#ede9fe` | `#5b21b6` |
| Announcement | `#1f2340` | white |

- **Bubbles:** the parent's own are accent with white text, on the right. The centre's are `#f2f4f9` with ink text, on the left. 16 px radius.
- **Shape:** buttons are pills, 44 px minimum height; inputs have an 8 px radius and are 44 px tall.
- **Accessibility:** every tappable item ≥ 44 × 44, an `accessibilityLabel` on icon buttons, text contrast ≥ 4.5:1, dynamic type respected.
- **Dark mode:** support it with the same tokens adjusted.

### Running against the backend
- **Local Laravel:** run `php artisan serve --host=0.0.0.0 --port=8000`. The base URL depends on where the app runs:

  | App runs on | `API_BASE` |
  |---|---|
  | Android emulator | `http://10.0.2.2:8000` |
  | iOS simulator | `http://127.0.0.1:8000` |
  | Real phone | `http://<computer's LAN IP>:8000` |

  On the local server the sign-in code isn't texted; it's in the backend's `storage/logs/laravel.log`.
- **Sandbox** (made-up families, and `/login/start` returns `debug_code`): run `scripts\parent-app-sandbox.ps1` in the backend and use port `8124`. In dev builds only, if `debug_code` is present, prefill the code field.

  Test parents:

  | Phone | Expect |
  |---|---|
  | 585-555-0134 | Kate: two children |
  | 585-555-0135 | Dan: same child as Kate |
  | 585-555-0177 | Olivia: a different family |
  | 585-555-0188, 585-555-0199 | Turned away |
- **Push:** real pushes need `eas init` (for the `projectId`) and a dev build; Expo Go is limited on Android. The backend's push driver is `log` until set to `expo`.

### Build it in this order, checking each step works before the next
1. Expo project, routes, theme, `api.ts`, `types.ts` (from the API docs).
2. Sign-in (phone → code → token) and the auth gate; `/ping` on a debug screen.
3. Inbox with badges, unread counts and pull-to-refresh.
4. Conversation: load, check for new messages, scroll up, send, mark read, the announcement state.
5. Children screen with Message the room / Message the office.
6. Photos (pick, upload, send, show).
7. Push (register, tap to open, sign-out cleanup).
8. Polish: empty states, error and offline banners (using `/ping`), loading skeletons, dark mode.

### Done means
- [ ] A test parent can sign in with the code, quit the app and reopen it still signed in.
- [ ] The inbox matches `GET /threads`, including unread counts and badges.
- [ ] A message sent in the app appears on the staff Messages page as "via the parent app".
- [ ] A staff reply appears in the open conversation within ~10 s, and the unread count clears on open.
- [ ] Announcements can't be replied to; **Message the office** opens the Office conversation.
- [ ] Photo send works and the photo shows on both sides.
- [ ] A `401` anywhere returns to sign-in with storage cleared.
- [ ] A turned-away number never gets past the code screen.
- [ ] Works on Android emulator and iOS simulator; layout fine at 360 px wide.
- [ ] No token, phone or message text written to the console in production builds.

Start by reading the three docs files, then show me the plan and the `types.ts` you derived from them before writing screens.
