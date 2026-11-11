# AngelNest Parent App API

How to connect the React parent app to AngelNest messaging.

The app reads and writes the **same conversations** as the staff Messages page:
- A message a parent sends from the app lands in the conversation the office already reads, marked "via the parent app".
- A message staff send reaches the app as a push notification.
- Parents without the app keep getting texts, exactly as before.

---

## 1. Server setup (once per server)

The API is part of the Laravel app. On each server (staging first, then live):

```bash
composer install --no-dev        # pulls in laravel/sanctum
php artisan migrate --force      # tokens, login codes, devices, read marks, messages.channel, children.state
php artisan config:clear
```

Optional `.env` settings (the defaults are safe):

| Setting | Default | What it does |
|---|---|---|
| `PARENT_APP_NAME` | `AngelNest` | Name used in the sign-in text ("Your AngelNest code is …") |
| `PARENT_APP_PUSH_DRIVER` | `log` | `log` writes pushes to `storage/logs/laravel.log`. `expo` sends real pushes. |
| `EXPO_ACCESS_TOKEN` | *(empty)* | Only needed if "Enhanced push security" is on in your Expo account |
| `PARENT_APP_REPLACES_SMS` | `false` | `true`: parents with the app get a push **instead of** a text |
| `PARENT_APP_TOKEN_DAYS` | `90` | How long a sign-in lasts before the parent must sign in again |

Sign-in codes are sent with the existing texting setup (`DAYCARE_SMS_DRIVER`). With the `log` driver no text leaves the server. The code is written to `storage/logs/laravel.log`, which is how you test sign-in before Twilio is live:

```
[sms] +17165550142 → +15855550134: Your AngelNest code is 633755. It expires in 10 minutes. …
```

> The live server (jordan.littleangelsdc.fun) holds real families. Build and test the app against **staging** or a local copy, with test families only.

---

## 2. Who can use the app

A parent can sign in only if **all** of these are true for at least one child:

1. They are linked to the child on the child record (**Family & pick-up**).
2. **Texts** is ticked on that link.
3. The link has no **restriction** (court order etc.).
4. The child is **Active** or **Pending**.
5. Their **mobile** number on file matches the number they type. The last 10 digits are compared, so `(585) 555-0134`, `585-555-0134` and `+15855550134` all match.

What they see:
- **Room** and **Office** conversations about their own children.
- **Announcements** sent since they were first added to a child's record.

The rules are checked on **every request**. Untick Texts, add a restriction or withdraw the child, and those conversations disappear from the app straight away.

Replying **STOP** to a text stops texts only. It does not lock anyone out of the app.

---

## 3. Basics

- **Base URL:** `https://<your-server>/api/parent`, for example `http://127.0.0.1:8000/api/parent` locally.
- **Every request** sends `Accept: application/json`.
- **Signed-in requests** also send `Authorization: Bearer <token>`.
- **Dates** are ISO 8601 with the centre's offset, e.g. `2026-11-06T09:49:00-05:00`.
- **IDs** are numbers.
- **Errors** are always JSON:

| Status | Meaning | Body |
|---|---|---|
| `401` | No token, a wrong one, or an expired one → send the parent to sign-in | `{"message":"Unauthenticated."}` |
| `403` | The token isn't a parent's | `{"message":"This is for the parent app."}` |
| `404` | Conversation or child not found, or not this parent's. The API never says which. | `{"message":"..."}` |
| `422` | Invalid input | `{"message":"...","errors":{"field":["..."]}}` |
| `429` | Too many tries → wait, then try again | `{"message":"Too Many Attempts."}` |

**Rate limits:**
- Requesting a code: 5 a minute per network and 10 an hour per phone number.
- Checking codes: 20 a minute.
- Everything else: 120 a minute per parent.

---

## 4. Endpoints

### 4.0 Check the connection
`GET /ping`. No sign-in needed. It says whether the server answers and whether the token sent (if any) is a live parent token:

```json
{ "connected": true, "api": "parent", "centre": "Little Angels Day Care Center", "server_time": "…", "signed_in": true, "parent": { "id": 1, "name": "Kate Testwell" } }
```

Use it for a "can't reach the centre" banner in the app, or as the first request in Postman.

### 4.1 Sign-in, step 1: text a code
`POST /login/start`

```json
{ "phone": "(585) 555-0134" }
```

The answer is the same whether or not the number is on file, so nobody can use it to find out which numbers you hold:

```json
{ "sent": true, "expires_in": 600, "message": "If that number is on file, a code is on its way." }
```

- The code is **6 digits**, lasts **10 minutes** and allows **5 tries**.
- Asking again replaces the previous code.

### 4.2 Sign-in, step 2: check the code and get a token
`POST /login/verify`

```json
{ "phone": "5855550134", "code": "633755", "device_name": "Kate's iPhone" }
```

`200`:
```json
{
  "token": "1|Fqt85Zhx…",
  "token_type": "Bearer",
  "expires_in_days": 90,
  "parent": { …same as GET /me… }
}
```

A wrong or expired code returns `422` with `errors.code`. Store the token securely (see §6.2) and send it on every later request.

### 4.3 Sign out
`POST /logout`. This deletes the token on the server. Also unregister the push token (§4.11) before you sign out.

### 4.4 The parent and their children
`GET /me`

```json
{
  "id": 1,
  "name": "Kate Testwell",
  "cell": "585-555-0134",
  "email": null,
  "children": [
    { "id": 1, "name": "Mia Testwell", "first_name": "Mia", "classroom": "Toddler", "status": "Active" }
  ],
  "centre": { "name": "Little Angels Day Care Center", "classroom_line": "(716) 555-0143", "office_line": "(716) 555-0142" }
}
```

### 4.5 Inbox
`GET /threads`, newest first.

```json
{
  "threads": [
    {
      "id": 1,
      "kind": "room",
      "kind_label": "Room · Toddler",
      "title": "Mia Testwell",
      "child": { "id": 1, "name": "Mia Testwell" },
      "can_reply": true,
      "unread": 1,
      "last_message": { "id": 1, "preview": "Picnic Friday, please bring sunscreen.", "from_me": false, "sent_at": "2026-11-06T09:49:00-05:00" },
      "last_message_at": "2026-11-06T09:49:00-05:00"
    }
  ]
}
```

| `kind` | Badge | Who on staff reads it | Can the parent reply? |
|---|---|---|---|
| `room` | Room · *classroom* | The child's teachers and the director | Yes |
| `office` | Office | Office and director only | Yes |
| `ann` | Announcement | Sent to everyone | **No** (`can_reply: false`) |

`unread` counts messages after the parent's read mark that they didn't write themselves.

### 4.6 Open a conversation
`GET /threads/{id}?limit=50`. Returns `{ "thread": {…as in the inbox…}, "messages": [ … ] }`, oldest to newest.

| Query | Use it for |
|---|---|
| *(none)* | The newest page (`limit` 1–100, default 50) |
| `before_id=123` | The page before message 123 (scrolling up) |
| `after_id=456` | Only messages newer than 456 (checking for new ones) |

A message:
```json
{
  "id": 2,
  "thread_id": 1,
  "from": { "type": "centre", "name": "Ray · Toddler", "is_me": false },
  "body": "Picnic Friday, please bring sunscreen.",
  "media": [ { "kind": "photo", "url": "https://…signed link…" } ],
  "channel": "sms",
  "sent_at": "2026-11-06T09:49:00-05:00"
}
```

- `from.type` is `centre` (staff) or `parent`.
- `from.is_me` is `true` on this parent's own messages; draw those on the right.
- `channel` is `sms` or `app`, showing how the message travelled.
- `media.url` is a signed link that lasts 30 days. Load it straight into an `<Image>`; it needs no token.

### 4.7 Send a message
`POST /threads/{id}/messages`

```json
{ "body": "Thanks, we'll bring it!", "media": [ { "path": "messages/media/parent-1/abc.jpg", "kind": "photo" } ] }
```

- Send `body`, `media`, or both. `body` is up to 1600 characters; `media` holds up to 4 items, each from §4.9.
- Answers `201` with `{ "message": { … } }`, the message as in §4.6. Add it to the screen straight away.
- An announcement answers `422`. Offer "Message the office" instead (§4.8).

### 4.8 Start a conversation
`POST /children/{childId}/threads`

```json
{ "kind": "office" }
```

Use `room` or `office`. It returns the one conversation of that kind for that child, creating it if there isn't one yet: `{ "thread": { … } }`.

`childId` is the `id` from `/me`. A child who isn't theirs answers `404`.

### 4.9 Upload a photo or video
`POST /uploads` as `multipart/form-data`, with one field named `file`.

- Accepted: jpeg, png, gif, webp, heic, mp4, mov or webm, up to 100 MB.
- Answers `201`:

```json
{ "path": "messages/media/parent-1/abc.jpg", "kind": "photo", "url": "https://…signed link…" }
```

Put `path` and `kind` into the `media` of §4.7. Only paths from this parent's own uploads are accepted.

### 4.10 Mark as read
`POST /threads/{id}/read` with `{ "last_message_id": 42 }`, or an empty body to mark everything read. Call it when a conversation is opened and when new messages arrive while it is open.

### 4.11 Push notifications
- `POST /devices` with `{ "push_token": "ExponentPushToken[xxxx]", "platform": "ios" }` registers a phone. `platform` is `ios`, `android` or `web`.
- `DELETE /devices` with `{ "push_token": "ExponentPushToken[xxxx]" }` unregisters it.

A token belongs to whichever parent registered it last.

When staff send to a family, an announcement or a room, each registered phone receives:
```json
{ "title": "Mia · Toddler", "body": "Picnic Friday, please bring sunscreen.", "data": { "thread_id": 1, "message_id": 7 } }
```

Use `data.thread_id` to open the right conversation when the notification is tapped.

A parent who switches **messages** or **announcements** off (§4.17) gets no push of that kind. Texts are not affected.

### Version 2 (added 9 Nov 2026)

These endpoints read what the centre already records, and invent nothing. Each needs the parent token. A child who isn't this parent's answers `404`, as in §4.8. `{childId}` is the `id` from `/me`.

### 4.12 A child's day
`GET /children/{childId}/daily?date=2026-11-09`. `date` is optional and defaults to today, in the centre's time zone.

```json
{
  "date": "2026-11-09",
  "status": { "state": "checked_in", "time": "2026-11-09T08:05:00-05:00", "by": "Kate Testwell" },
  "totals": null,
  "entries": [
    { "id": 12, "time": "2026-11-09T08:05:00-05:00", "type": "checkin", "title": "Checked in", "detail": "Dropped off by Kate Testwell" }
  ],
  "note": null
}
```

- **`status.state`:**
  - `checked_in` or `checked_out` come from the register (`attendances`). `by` is who pressed the door kiosk.
  - `absent` comes from a parent's report (§4.15) or the register's DSS absent mark.
  - Otherwise it's `not_arrived`.
- **`entries`:** one per door punch (`checkin` / `checkout`), or the register's times when there are no punches.
- **`totals` and `note` are `null`:** meals, naps, changes and teachers' notes aren't recorded at the centre yet.

### 4.13 Photos
`GET /children/{childId}/photos?page=1`. These are the photos staff sent in this parent's conversations about the child (Room, Office) and in announcements, grouped by day, newest first, 7 days a page.

```json
{
  "groups": [
    { "date": "2026-11-09", "label": "TODAY", "caption": "Painting with the Toddler room this morning!",
      "photos": [ { "id": 81, "thumb_url": "https://…signed…", "full_url": "https://…signed…", "taken_at": "2026-11-09T10:12:00-05:00" } ] }
  ],
  "next_page": null
}
```

The links are signed, like `media.url` (§4.6), and need no token.

### 4.14 Calendar
`GET /events?month=2026-11`. `month` is optional and defaults to this month.

```json
{ "month": "2026-11", "events": [ { "id": 3, "date": "2026-11-26", "kind": "closed", "title": "Thanksgiving", "detail": null } ] }
```

Today the events are the centre's closure days (`closure_days`, including generated holidays), all with `kind: "closed"`. Other events will join them when the centre has an events list.

### 4.15 Report an absence
`POST /absences`

```json
{ "child_id": 1, "dates": ["2026-11-10", "2026-11-11"], "reason": "sick", "symptoms": ["fever"], "note": "Back Thursday." }
```

- **`dates`:** 1–31 days, today or later, with no repeats.
- **`reason`:** `sick`, `appointment`, `family`, `vacation` or `other`.
- **`symptoms`:** `fever`, `cough`, `stomach` or `rash`. They're kept only when the reason is `sick`.
- **`note`:** up to 1000 characters.

Answers `201`:

```json
{ "absences": [ { "id": 1, "child_id": 1, "date": "2026-11-10", "reason": "sick" }, { "id": 2, "child_id": 1, "date": "2026-11-11", "reason": "sick" } ] }
```

The report is saved in `parent_absence_reports`, and posted as a message from the parent in the child's **Room** conversation: *"Absence: Mia will be away Tue, Nov 10 and Wed, Nov 11. Reason: Sick (fever). Note: Back Thursday."* The teachers see it in Messages. Reporting the same day again replaces it. **DSS marks are not touched:** the register stays the director's.

### 4.16 Pickup
`GET /children/{childId}/pickup`

```json
{
  "code": null,
  "expires_at": null,
  "code_hint": "Use your own door PIN at the front-desk kiosk. Ask the office if you need one.",
  "people": [
    { "id": 5, "name": "Dan Testwell", "relation": "Father", "is_parent": true },
    { "id": 4, "name": "Kate Testwell", "relation": "Mother", "is_parent": true }
  ],
  "today_person_id": 5
}
```

- **`people`** is the pick-up list: everyone linked to the child with **Pick-up** ticked and no restriction, guardians first. `id` is the person's id.
- **`code` is always `null`.** Door PINs are per adult and stored only as hashes, so the API can't send one.
- **`today_person_id`** is who the parent said is collecting today, or `null`.

Two writes, each answering the whole object above and posting a note in the child's **Office** conversation:

- `PUT /children/{childId}/pickup/today` with `{ "person_id": 5 }`. The person must be on the pick-up list, otherwise `422` on `person_id`. The note reads *"Pickup today: Dan Testwell (Father) will collect Mia."*
- `POST /children/{childId}/pickup/one-time` with `{ "name": "Sam Lee", "relation": "Neighbour", "phone": "585-555-0100" }` answers `201`. The person appears in `people` as `{ "id": 0, …, "one_time": true }`, and `today_person_id` is `0`. **They are never added to the pick-up list.** The note asks the office to check photo ID.

### 4.17 Notification settings
- `GET /notification-preferences` answers `{ "messages": true, "announcements": true, "daily": true, "photos": true }`. Everything is on until changed.
- `PUT /notification-preferences` with any of those keys as booleans answers the full set.
- `messages` and `announcements` control the pushes in §4.11. `daily` and `photos` are stored for pushes the centre doesn't send yet.

---

## 5. How the app should behave

```
Launch ── token saved? ── no ──► Phone screen ─► POST /login/start ─► Code screen ─► POST /login/verify
   │                                                                                  │ save token
   └── yes ─► GET /me ── 401? ─► clear token, back to Phone screen ◄──────────────────┘
               │
               ▼
          Inbox (GET /threads, check every 30 s while on screen, and on app foreground / push)
               │ tap
               ▼
          Conversation (GET /threads/{id}, then check with after_id every 10 s while open,
                        POST /read on open and on new messages, POST /messages to send)
```

---

## 6. Connecting a React Native (Expo) app

### 6.1 Create the app and add the packages
```bash
npx create-expo-app@latest angelnest-parent
cd angelnest-parent
npx expo install expo-secure-store expo-notifications expo-device expo-constants expo-image-picker
npm install @tanstack/react-query
```

Add the base URL to `app.json`:
```json
{ "expo": { "extra": { "apiBase": "http://192.168.1.20:8000/api/parent" } } }
```

On a real phone, `127.0.0.1` means the phone itself. Use your computer's network IP, and run Laravel with `php artisan serve --host=0.0.0.0 --port=8000`.

### 6.2 The API client: `src/api.ts`
```ts
import Constants from 'expo-constants';
import * as SecureStore from 'expo-secure-store';

const BASE = Constants.expoConfig?.extra?.apiBase as string;
const TOKEN_KEY = 'angelnest_token';

export class ApiError extends Error {
  constructor(public status: number, message: string, public errors?: Record<string, string[]>) { super(message); }
}

export const getToken = () => SecureStore.getItemAsync(TOKEN_KEY);
export const setToken = (t: string) => SecureStore.setItemAsync(TOKEN_KEY, t);
export const clearToken = () => SecureStore.deleteItemAsync(TOKEN_KEY);

let onSignedOut: () => void = () => {};
export const whenSignedOut = (fn: () => void) => { onSignedOut = fn; };

export async function api<T>(path: string, options: { method?: string; body?: unknown; form?: FormData } = {}): Promise<T> {
  const token = await getToken();
  const res = await fetch(BASE + path, {
    method: options.method ?? (options.body || options.form ? 'POST' : 'GET'),
    headers: {
      Accept: 'application/json',
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: options.form ?? (options.body ? JSON.stringify(options.body) : undefined),
  });

  const data = await res.json().catch(() => ({}));
  if (res.status === 401) { await clearToken(); onSignedOut(); }
  if (!res.ok) throw new ApiError(res.status, data.message ?? 'Something went wrong', data.errors);
  return data as T;
}
```

### 6.3 Types: `src/types.ts`
```ts
export type Child = { id: number; name: string; first_name: string; classroom: string | null; status: string };
export type Me = { id: number; name: string; cell: string; email: string | null; children: Child[];
  centre: { name: string; classroom_line: string; office_line: string } };
export type Thread = { id: number; kind: 'room' | 'office' | 'ann'; kind_label: string; title: string;
  child: { id: number; name: string } | null; can_reply: boolean; unread: number;
  last_message: { id: number; preview: string; from_me: boolean; sent_at: string } | null; last_message_at: string | null };
export type Message = { id: number; thread_id: number; from: { type: 'centre' | 'parent'; name: string; is_me: boolean };
  body: string | null; media: { kind: 'photo' | 'video'; url: string }[]; channel: 'sms' | 'app'; sent_at: string };
```

### 6.4 Sign-in
```ts
import { api, setToken } from './api';
import type { Me } from './types';

export const startLogin = (phone: string) => api('/login/start', { body: { phone } });

export async function verifyLogin(phone: string, code: string, deviceName: string) {
  const r = await api<{ token: string; parent: Me }>('/login/verify', { body: { phone, code, device_name: deviceName } });
  await setToken(r.token);
  return r.parent;
}
```

Show `error.errors?.code?.[0]` under the code box on a `422`. On a `429`, show "Too many tries — wait a minute."

### 6.5 Inbox and conversation with React Query
```ts
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from './api';
import type { Message, Thread } from './types';

export const useInbox = () =>
  useQuery({ queryKey: ['threads'], queryFn: () => api<{ threads: Thread[] }>('/threads').then(r => r.threads), refetchInterval: 30_000 });

export function useConversation(threadId: number) {
  return useQuery({
    queryKey: ['thread', threadId],
    queryFn: () => api<{ thread: Thread; messages: Message[] }>(`/threads/${threadId}`),
    refetchInterval: 10_000,            // simple and reliable; add after_id later if threads get long
  });
}

export function useSend(threadId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { body?: string; media?: { path: string; kind: 'photo' | 'video' }[] }) =>
      api<{ message: Message }>(`/threads/${threadId}/messages`, { body: input }),
    onSuccess: ({ message }) => {
      qc.setQueryData<{ thread: Thread; messages: Message[] }>(['thread', threadId], old =>
        old ? { ...old, messages: [...old.messages, message] } : old);
      qc.invalidateQueries({ queryKey: ['threads'] });
    },
  });
}

export const markRead = (threadId: number, lastId?: number) =>
  api(`/threads/${threadId}/read`, { body: lastId ? { last_message_id: lastId } : {} });
```

When rendering:
- `from.is_me` on the right, everyone else on the left; `from.name` and the time under each bubble.
- For `ann` threads, hide the reply box and show "Announcements are one-way" with a **Message the office** button that calls `POST /children/{id}/threads {kind:'office'}`.

### 6.6 Photos
```ts
import * as ImagePicker from 'expo-image-picker';
import { api } from './api';

export async function pickAndUpload() {
  const r = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images', 'videos'], quality: 0.8 });
  if (r.canceled) return null;
  const a = r.assets[0];
  const form = new FormData();
  form.append('file', { uri: a.uri, name: a.fileName ?? 'upload.jpg', type: a.mimeType ?? 'image/jpeg' } as any);
  return api<{ path: string; kind: 'photo' | 'video'; url: string }>('/uploads', { form });
}
// then: send.mutate({ body, media: [{ path: up.path, kind: up.kind }] })
```

### 6.7 Push notifications
```ts
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import Constants from 'expo-constants';
import { Platform } from 'react-native';
import { api } from './api';

export async function registerForPush() {
  if (!Device.isDevice) return null;                       // simulators have no push
  const { status } = await Notifications.requestPermissionsAsync();
  if (status !== 'granted') return null;
  const projectId = Constants.expoConfig?.extra?.eas?.projectId;
  const { data: token } = await Notifications.getExpoPushTokenAsync({ projectId });
  await api('/devices', { body: { push_token: token, platform: Platform.OS === 'ios' ? 'ios' : 'android' } });
  return token;
}

// After sign-in: registerForPush(). On sign-out: api('/devices', { method: 'DELETE', body: { push_token } }) then api('/logout', { body: {} }).
// Open the right conversation when a push is tapped:
Notifications.addNotificationResponseReceivedListener(r => {
  const threadId = r.notification.request.content.data?.thread_id;
  if (threadId) {/* navigate to Conversation(threadId) */}
});
```

To send real pushes:
1. Run `npx eas init` to create the Expo project and its `projectId`.
2. Set up iOS and Android credentials with `eas credentials`.
3. On the server, set `PARENT_APP_PUSH_DRIVER=expo`.

Until then, pushes appear in `storage/logs/laravel.log` as `[push] …`.

### 6.8 A web app instead of React Native
The same `api.ts` works in a browser. Store the token in memory or `localStorage` instead of SecureStore, and skip §6.7; web push needs its own setup.

The server already answers the browser's CORS checks for `/api/*`. To limit which sites may call it, publish and edit `config/cors.php` (`php artisan config:publish cors`).

---

## 7. Test it before the app exists: the sandbox and the tester page

Your local `.env` points at a copy of the real database, so don't add test parents there. The sandbox is a separate AngelNest:
- its own SQLite file (`database/parent-sandbox.sqlite`)
- made-up families
- texts and pushes written to the log only

The seeder refuses to run on any other database.

**1. Start the sandbox.** In a PowerShell window at the project folder:
```powershell
powershell -ExecutionPolicy Bypass -File scripts\parent-app-sandbox.ps1
```
It empties and reseeds the sandbox on every run, then serves it at `http://127.0.0.1:8124`. If it warns that Vite isn't running, start `npm run dev` in another window so the staff pages are styled. Ctrl+C stops it.

**2. Open the parent tester.** Open `docs/parent-app-tester.html` straight from disk by double-clicking it. It acts as the parent app:
- sign in with a phone number; in the sandbox the code is filled in for you
- inbox, conversation, reply, photo
- start a Room or Office conversation
- the last API answer under **Last API answer**

Each browser tab is its own parent.

**3. Open the staff side.** Go to `http://127.0.0.1:8124/messages` in another window.

| Sign-in | Who |
|---|---|
| `director@sandbox.test` / `sandbox-pass` | Director: sees every conversation |
| `teacher@sandbox.test` / `sandbox-pass` | Toddler teacher: sees Toddler Room conversations only |

| Parent's mobile | Who | Expect |
|---|---|---|
| 585-555-0134 | Kate: Mia (Toddler) and Leo (PreK) | Signs in; sees Mia's Room and Office conversations and the announcement |
| 585-555-0135 | Dan: Mia | Signs in; sees the **same** Mia conversations as Kate |
| 585-555-0177 | Olivia: Ava | Signs in; sees only Ava's |
| 585-555-0188 | Nora: Texts not ticked | No code |
| 585-555-0199 | Rex: court restriction | No code |

**4. Things to try**
1. Kate replies in Mia's Room conversation. On the staff page it appears as *Kate Testwell · via the parent app*, marked unread. The Toddler teacher sees it too; an Office reply is hidden from the teacher.
2. Staff reply from the Messages page. Within 5 seconds it appears in Kate's tester tab, and in Dan's.
3. Open the announcement. There's no reply box; use **Office · Mia** to write to the office instead.
4. Sign in as Olivia in a third tab. Nothing of Mia's or Leo's is there.
5. On the staff side, open Mia's record → Family & pick-up → Edit Kate → untick **Texts** → Save. Kate's tester shows nothing for Mia on its next refresh.
6. Send a photo from the tester; it shows on the staff page.
7. Ask for codes more than 5 times in a minute. The 6th answers *Too Many Attempts*.

The tester shows times in your computer's time zone and the staff page in the centre's, so a parent abroad sees different clock times; both are the same moment. The sandbox only returns the code in the API answer because it runs with `APP_ENV=local` and `PARENT_APP_SHOW_CODES=true`. A real server never does, and the code arrives by text.

---

### Postman

`docs/parent-app.postman_collection.json` has every endpoint, in order, with its docs on each request's **Docs** tab. Import it in Postman (**Import** → drag the file in) and run:
- **0** to check the connection
- **1 → 2** to sign in; the token is saved and sent for you
- **4** to pick a conversation, then the rest

It points at your local server (`http://127.0.0.1:8000/api/parent`) by default. Change `base_url` on the collection's **Variables** tab for the sandbox (`:8124`).

On the local server the sign-in code is in `storage/logs/laravel.log`; in the sandbox it fills in by itself. To see a staff message arrive, send it from `/messages`, then press **6. Check for new messages**.

## 8. Where it lives in the code

| What | File |
|---|---|
| Routes | `routes/api.php` |
| Endpoints | `app/Http/Controllers/Api/ParentAppController.php` |
| Every rule (who, what, unread, push audience) | `app/Services/ParentPortal.php` |
| Push sending (log / Expo) | `app/Services/ParentPush.php` |
| Staff send → push | `Messaging::deliver()` in `app/Services/Messaging.php` |
| Parent-only middleware | `app/Http/Middleware/EnsureParent.php` |
| Rate limits | `app/Providers/AppServiceProvider.php` |
| Tables | `database/migrations/2026_11_06_000002_create_parent_app_tables.php` |
| Tests | `tests/Feature/ParentAppApiTest.php` (15 tests, one per rule) |
| Version 2 endpoints (§4.12–4.17) | `app/Http/Controllers/Api/ParentDayController.php` |
| Version 2 rules (the day, photos, calendar, absences, pickup) | `app/Services/ParentDay.php` |
| Version 2 tables | `database/migrations/2026_11_09_000001_create_parent_app_v2_tables.php` (models `ParentAbsenceReport`, `ParentPickupPlan`, `ParentNotificationPref`) |
| Version 2 tests | `tests/Feature/ParentAppDayTest.php` (12 tests) |
