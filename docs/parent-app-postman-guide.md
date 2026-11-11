# Testing the Parent App API in Postman

How to test `docs/parent-app.postman_collection.json` against your local AngelNest (`http://127.0.0.1:8000`). With this collection you sign in yourself; steps 3–5 get you a token.

For what each endpoint does in detail, see each request's **Docs** tab in Postman, or [parent-app-api.md](parent-app-api.md).

---

## 1. Before you start

- Your local AngelNest is running: `http://127.0.0.1:8000` shows the login page.
- Your local database has the parent-app tables. If you haven't yet, run `php artisan migrate` once.
- A **VS Code terminal** is open in the project folder; you read the sign-in code from it.
- You know the mobile of a parent who can sign in. The parent must:
  - be linked to a child on the child's record, under **Family & pick-up**;
  - have **Texts** ticked on that link;
  - have no restriction on that link;
  - have a child who is **Active** or **Pending**;
  - have a full 10-digit mobile on file.

  Below, that number is written `(XXX) XXX-XXXX`.

## 2. Import the collection

1. In Postman, delete any old **AngelNest Parent App API** collection: right-click it → **Delete**.
2. Click **Import** (top left) and drag in `docs\parent-app.postman_collection.json`.
3. Open **AngelNest Parent App API** on the left. It has four folders:
   - **1. Sign in**
   - **2. Conversations**
   - **3. Photos**
   - **4. Push and sign out**

## 3. Check the connection

Open **1. Sign in → 0. Check connection** and press **Send**:

```json
{ "connected": true, "api": "parent", "centre": "Little Angels Day Care Center", "signed_in": false, "parent": null }
```

`connected: true` means Postman reaches your server. `signed_in: false` is expected for now. If you get *Could not send request*, your local server isn't running.

## 4. Ask for a sign-in code

1. Open **1. Text me a code** → **Body** tab and replace the text with the parent's mobile:
   ```json
   { "phone": "(XXX) XXX-XXXX" }
   ```
   Typing it here is easier than using the `phone` variable; see [the variable trap](#the-variable-trap).
2. Press **Send**. You get **200**:
   ```json
   { "sent": true, "expires_in": 600, "message": "If that number is on file, a code is on its way." }
   ```
3. Locally nothing is texted; the code goes to the log. In the VS Code terminal:
   ```powershell
   Select-String "AngelNest code" storage\logs\laravel.log | Select-Object -Last 1
   ```
   The newest line ends with the parent's number and `Your AngelNest code is 482913 …`. Copy the 6 digits. The code works for **10 minutes** and allows **5 tries**.

> No new line in the log? That number can't sign in. The server gives the same "sent" answer either way on purpose. Check the list in step 1.

## 5. Sign in and get the token

1. Open **2. Check the code → token** → **Body** and replace the text with the same phone and your code:
   ```json
   { "phone": "(XXX) XXX-XXXX", "code": "482913", "device_name": "Postman" }
   ```
2. Press **Send**. You get **200**:
   ```json
   {
     "token": "2|xxxxxxxxxxxxxxxxxxxxxxxx",
     "token_type": "Bearer",
     "expires_in_days": 90,
     "parent": { "id": 8, "name": "…", "children": [ { "id": 1, "name": "…", "classroom": "Toddler" } ], "centre": { … } }
   }
   ```
3. **Check the token was saved:**
   1. Click the collection name and open the **Variables** tab.
   2. `token` should have the long `2|…` text in its **Current value**.
   3. If it's empty, copy the token from the answer, paste it into **Current value**, and press **Ctrl+S**.

   Every other request sends it for you as `Authorization: Bearer {{token}}`.
4. Run **0. Check connection** again. It should now say `"signed_in": true` with the parent's name.

## 6. See the conversations

1. **4. Inbox** → **Send**: the parent's conversations, newest first.

   | Field | Meaning |
   |---|---|
   | `kind` | `room` (the child's teachers), `office` (office only) or `ann` (announcement) |
   | `kind_label` | The badge, e.g. *Room · Toddler* |
   | `unread` | Messages after the parent's read mark that they didn't write |
   | `can_reply` | `false` for announcements |

   The first conversation the parent can reply to is saved as `thread_id`.
2. **5. Open conversation** → **Send**: every message, oldest first.
   - `"from": { "type": "centre" }` means staff.
   - `"is_me": true` means this parent.
   - `channel` is `sms` or `app`.
3. **To open a different conversation:**
   1. On **Variables**, set `thread_id`'s **Current value** to its `id` from the inbox.
   2. Press **Ctrl+S**.
   3. Run **5** again.

## 7. Two-way test with the staff Messages page

1. **8. Send a message** → **Body**, e.g. `{ "body": "Hi, we will be late tomorrow." }` → **Send**. You get **201**.
2. In a browser, open `http://127.0.0.1:8000/messages` with your usual staff login and open that family's conversation. The message is there, labelled **"via the parent app"**, and shows as unread.
3. Type a reply on that page and press **Send**.
4. Back in Postman, run **6. Check for new messages**. The staff reply comes back in `"messages"`, and **View → Show Postman Console** prints it as `NEW  … : your reply`.
5. Run **9. Mark as read**, then **4. Inbox**. That conversation's `unread` is now `0`.

Postman only shows new messages when you press **Send** on request 6; it can't be notified on its own. The real app checks every few seconds and gets push notifications.

## 8. Optional extras

| Request | What to do | What happens |
|---|---|---|
| **7. Older messages** | Send | The page of messages before `last_message_id` (scrolling up) |
| **10. Start an Office conversation** | Send | Opens the child's Office conversation and makes it the current `thread_id` |
| **11. Upload a photo** | Body → form-data → on the `file` row click **Select Files** → Send | Uploads it and saves its path |
| **12. Send the photo** | Send (after 11) | The photo shows on the staff page |
| **13. Register this device** | Send | While registered, staff messages write a `[push]` line to `storage\logs\laravel.log` |
| **14. Forget this device** | Send | Stops that |
| **15. Sign out** | Send | Cancels the token. Every request then gets **401** until you repeat steps 4–5 |

## 9. If something goes wrong

| You see | What to do |
|---|---|
| **Could not send request** | Start your local server |
| **422** on request 1: *"The phone field is required."* | Type the phone in the Body as in step 4 |
| **422** on request 2: *"That code is wrong or has expired."* | Run request 1 again and use the **newest** code from the log within 10 minutes |
| **422** on request 2: *"The code field is required."* | Type the code in the Body as in step 5 |
| No new line in the log after request 1 | That phone can't sign in; check the list in step 1 |
| **401 Unauthenticated** | No token: check `token`'s **Current value** (step 5.3), or you signed out (15) |
| **404** | `thread_id` is empty or isn't this parent's conversation; run **4** first |
| **422** on 8: *"Announcements are one-way"* | Switch to a Room or Office conversation, or run **10** |
| **429 Too Many Attempts** | Over 5 code requests a minute (or 10 an hour for one number); wait |

### The variable trap

Postman variables have an **Initial value** and a **Current value**, and requests use the **Current value**. If you type a value into *Initial value* only, requests still send the old (often empty) one. Put values in **Current value**, or in both, and press **Ctrl+S**. That's why steps 4 and 5 type the phone and code straight into the Body.

## 10. Test another parent

Repeat steps 4–5 with the other parent's mobile. They see only their own children's conversations. A parent with **Texts** unticked, or with a restriction, gets no code.

Messages you send are saved in your local database, inside that family's real conversation. Nothing is texted locally, because Twilio isn't set up there.
