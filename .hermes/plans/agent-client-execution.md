# Agent Mode — Client-Side Execution Architecture

> **Goal:** Backend orchestrates AI tool-calling loop, but file operations (create/edit/delete) are executed by the SwiftUI client on the user's device — like Cursor.

**Architecture:** State-token pattern. Backend runs AI → yields tool requests via SSE → saves state to cache → ends stream. Client executes tools locally → POSTs results back. Backend resumes AI loop. Repeats until final response.

**Why:** PHP SSE streams can't pause and wait for external input. Docker can't access host filesystem.

---

## Flow

```
User: "buatkan login.html"  (agent mode, workspace=/Users/raka/Desktop/project)

→ POST /api/chat/rooms/{id}/send  {chat_mode: "agent", workspace_path: "..."}
  → Backend: runs AI with tool definitions
  → AI responds with <tool_call>write_file("login.html", "...")</tool_call>
  → Backend: saves state (messages + iteration) to cache with state_token
  → Backend: streams tool_requests to client via SSE, then ends

← Client receives: [{tool: "write_file", path: "login.html", content: "..."}]
  → Client shows "Agent wants to create login.html — Allow?"
  → User approves → Client writes file locally
  → POST /api/chat/rooms/{id}/agent-continue  {state_token, results: [...]}

  → Backend: loads state from cache, feeds results to AI
  → AI responds: "✅ File login.html telah dibuat"
  → Backend: streams final response, cleans state → DONE
```

## Files Changed

| File | Change |
|------|--------|
| `app/Services/AI/AgentProcessor.php` | Rewrite: yield tool_requests, save/load state, two-phase execution |
| `app/Services/AI/ChatProcessor.php` | Pass state_token handling |
| `app/Http/Controllers/Api/ChatApiController.php` | Add `agentContinue` endpoint, modify send for agent mode |
| `routes/api.php` | Add `POST /chat/rooms/{room}/agent-continue` |
| `Omoikane/Features/Chat/Views/ChatRoomView.swift` | Handle `agent_tool_request` events, execute locally, POST results |
| `Omoikane/Core/APIClient.swift` | Add `sendAgentContinue()` method |

---

## Task 1: Rewrite AgentProcessor — yield tool_requests, state save/load

**File:** `app/Services/AI/AgentProcessor.php`

Key changes:
- `process()` now returns phase info: `state_token` + `tool_requests` via SSE
- `continue()` is a new method that resumes from saved state
- State stored in Laravel cache with 5-min TTL

## Task 2: Add state_token to ChatProcessor agent routing

**File:** `app/Services/AI/ChatProcessor.php`

Pass state_token in options. Add agent-continue routing.

## Task 3: Add agent-continue endpoint

**Files:**
- `app/Http/Controllers/Api/ChatApiController.php` — `agentContinue()` method
- `routes/api.php` — `POST /chat/rooms/{room}/agent-continue`

## Task 4: SwiftUI — handle tool requests + execute locally

**Files:**
- `ChatRoomView.swift` — intercept `agent_tool_request` SSE events
- `APIClient.swift` — add `sendAgentContinue()`

Show permission dialog (or auto-approve), execute file operations with FileManager, send results back.

## Task 5: Verify end-to-end

Test: "buatkan test.html" in agent mode → file created on host filesystem → chat shows confirmation.
