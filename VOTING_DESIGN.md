# Voting System — Design Document

## Core goal

Allow users to vote which Minecraft server they want to play on, from a set of available servers. Not all servers run simultaneously — the machine has limited resources. The voting system decides which server starts next, while respecting resource constraints and player safety.

---

## Key principles

1. **Never stop a container with players in it** — no matter how many votes another server has
2. **Never start a server if resource limits do not allow it** — resource check is based on actual Docker container state, not just player count
3. **Grace period protects reconnecting players** — an empty server is not stopped immediately
4. **Auto-stop only when needed** — a running empty server is only stopped if another server is waiting to start and is resource-blocked by it
5. **Resource awareness** — valid running combinations are explicitly configured
6. **One vote per active user** — votes from idle/disconnected users do not count
7. **Visual clarity** — servers ranked by vote count, ordered top to bottom dynamically
8. **Transparency** — if a server cannot start, the reason is shown clearly

---

## Memory profiles

### Declaration — from container environment variable

Set when the Minecraft server container is started via `mc-server-start.sh`:

```bash
-e MEMORY_PROFILE=medium   # low | medium | high
```

Read from `Config.Env` in `DockerClient::inspectContainer()` response.
Default if absent: `medium`.

`DockerClient` gets a helper: `getMemoryProfile(array $inspectData): string`

### Profile hierarchy (resource cost, ascending)
```
low < medium < high
```

### Valid running combinations — global meta.yaml

At `/mc-data/config/meta.yaml` (`~/mc-server-manager-data/meta.yaml`):

```yaml
resource_limits:
    - [high: 1, low: 1]
    - [medium: 2]

vote_threshold: 3
vote_cooldown: 300       # seconds
server_empty_grace: 60   # seconds before auto-stopping an empty server
```

### Resource budget algorithm

```
runningProfiles = MEMORY_PROFILE of all containers with Docker state = running
candidateProfile = MEMORY_PROFILE of server to start

for each allowed_combination in resource_limits:
    if (runningProfiles + candidateProfile) fits within allowed_combination:
        → allowed
→ if no combination matched: blocked
```

Resource check uses **actual Docker container state** — a container that is stopping or in grace period still counts as running until Docker reports it as `stopped`.

**Examples** with `[[high:1, low:1], [medium:2]]`:

| Currently running | Want to start | Result |
|---|---|---|
| (none) | high | ✅ fits `[high:1, low:1]` |
| (none) | medium | ✅ fits `[medium:2]` |
| high | low | ✅ fits `[high:1, low:1]` |
| high | medium | ❌ no match |
| medium | medium | ✅ fits `[medium:2]` |
| medium | high | ❌ no match |
| medium + low | anything | ❌ no match |

---

## Grace period — protecting reconnecting players

When the last player leaves a running server:

1. `MinecraftMonitor` detects `players = 0` via log stream
2. Stores grace timestamp in Redis: `server_empty:{serverName}` = current Unix timestamp (TTL = `server_empty_grace` seconds)
3. Broadcasts `server_update` — clients show a countdown on that server's card
4. **If a player rejoins before grace expires:**
   - `MinecraftMonitor` detects player join, deletes `server_empty:{serverName}`
   - Broadcasts `server_update` — countdown disappears
   - No further action
5. **If grace expires with still 0 players:**
   - Check if any other server has votes >= threshold AND is resource-blocked by this server
   - If yes → auto-stop this server (see auto-stop flow)
   - If no → do nothing, leave server running

The grace period countdown is shown on the card of the **empty server**, not the waiting server.

### Redis key

| Key | Type | TTL | Content |
|---|---|---|---|
| `server_empty:{serverName}` | string (int) | `server_empty_grace` seconds | Unix timestamp when empty was first detected |

### Grace period expiry detection

`MinecraftMonitor::refreshStats()` runs every 10s. Add a check:
- If `server_empty:{name}` key is absent but was previously set → grace has expired
- Alternative: `refreshStats()` checks `time() >= grace_started_at + grace_duration` directly

---

## Auto-stop flow

Auto-stop only triggers when ALL of these are true:
1. Server has `players = 0`
2. Grace period has expired
3. At least one other stopped server has active votes >= its threshold
4. That waiting server is resource-blocked by this running server

If all conditions met:
1. Call `DockerClient::stopContainer(containerId)`
2. Delete `server_empty:{serverName}` from Redis
3. Broadcast `server_update` for this server (state = stopping)
4. Wait for `MinecraftMonitor::scan()` to detect Docker state = `stopped`
5. Once stopped, `scan()` updates Redis and calls `VoteManager::checkAndTrigger(voteLeader)`
6. `ResourceBudgetChecker::canStart()` now returns true (container is stopped)
7. Vote leader starts

**Never call `checkAndTrigger()` while the stopping container is still in Docker state `running` or `stopping`.**

### DockerClient additions
- `stopContainer(string $id): void` — `POST /containers/{id}/stop`

---

## Auto-start trigger — full check sequence

When `VoteManager::checkAndTrigger(serverName)` is called:

1. ✅ Server is stopped (Docker state = `stopped`, confirmed via Redis `server:{name}.running = false`)
2. ✅ No vote cooldown active (`vote_cooldown:{name}` absent in Redis)
3. ✅ No running server has `players > 0`
4. ✅ No running server is in grace period (`server_empty:{name}` key present) — grace period server still counts as running for resource purposes
5. ✅ `ResourceBudgetChecker::canStart(profile)` returns true based on actual running containers

If all pass → auto-start via `DockerClient::restartContainer()`, clear votes, apply cooldown.

---

## Voting rules

### 1. Eligibility
- `ROLE_USER` and `ROLE_ADMIN` only
- Must have active heartbeat (within last 120 seconds)
- Anonymous: read-only

### 2. One vote per user
- One active vote at a time
- Vote for different server = moves vote
- Vote for same server again = retracts vote (toggle)

### 3. Active vote counting
- Only votes from users with active heartbeat count toward threshold
- Inactive votes stored but ignored for threshold and display

### 4. Vote threshold
- Global default: `vote_threshold` in global `meta.yaml`
- Per-server override: `vote_threshold` in server's `mc-server-manager/meta.yaml`

### 5. Manual admin start
- Bypasses vote system entirely
- Resource limits NOT enforced (admin responsibility)
- Clears votes for that server
- No cooldown applied
- No grace period check

### 6. Server ordering on homepage
- Dynamically sorted by active vote count, descending
- Tied servers: alphabetical order
- Updates live via WebSocket on every vote change
- Admin dashboard: alphabetical order (unchanged)

---

## Visual design

### Position medals (card header, left of server name)

| Position | Badge |
|---|---|
| 1st | 👑 `#1` gold |
| 2nd | 🥈 `#2` silver |
| 3rd | 🥉 `#3` bronze |
| 4th+ | `#N` dark |

### Grace period countdown (shown on empty running server card)

```
┌──────────────────────────────────────────────────────┐
│  ⏱ Server stopping in 45s                           │
│  [████████████░░░░░░░░░░░░]                          │
│  Waiting for players to reconnect...                 │
└──────────────────────────────────────────────────────┘
```

- Progress bar depleting in real time (JS calculates from `grace_started_at` timestamp)
- Disappears immediately if a player rejoins
- `alert-info` styling

### Vote section (bottom of each server card)

```
┌──────────────────────────────────────────────────────┐
│  [Av] [Av] [Av]   2 / 3 votes                       │
│                   [  Vote to start  ]                 │
└──────────────────────────────────────────────────────┘
```

- Voter avatars: 32px circular, max 5 + `+N more`
- Vote button states:
  - Not voted: `Vote to start`
  - Voted: `✓ Voted — click to retract`
  - Server running: button hidden, `Server is running ✅`
  - Cooldown active: button disabled, `Starting soon...`
  - Anonymous: no button

### Callout blocks (shown above vote section when server cannot start)

**Blocked by players on another server** (`alert-info`):
> ⏳ **Waiting for players to leave**
> server2 still has 2 players online. This server will start automatically once they leave.

**Blocked by empty server in grace period** (`alert-info`):
> ⏳ **Waiting for server to stop**
> server2 is empty and stopping. This server will start automatically once it stops.

**Blocked by resource constraints** (`alert-warning`):
> ⚠️ **Insufficient resources**
> This server requires high memory resources. Waiting for other servers to free up resources before it can start.

Callouts stack if multiple conditions apply.

### Disabled card state (resource blocked)
- Card header muted/greyed
- `Unavailable` badge in secondary colour
- Voting still allowed
- Callout explains why

---

## Redis schema additions

| Key | Type | TTL | Content |
|---|---|---|---|
| `votes` | hash | — | `{ gamertag => serverName }` (existing) |
| `heartbeat:{gamertag}` | string | 120s | Unix timestamp (existing) |
| `vote_cooldown:{serverName}` | string | configurable | `"1"` — present = cooldown active |
| `server_empty:{serverName}` | string (int) | `server_empty_grace` secs | Unix timestamp when empty detected |

---

## New/modified files

### Backend
- **`src/Controller/VoteController.php`** — `POST /server/{name}/vote`
- **`src/Service/VoteManager.php`** — core vote + auto-stop + auto-start logic
- **`src/Service/ResourceBudgetChecker.php`** — combination-based resource check using actual Docker state
- **`src/Service/GlobalMetaReader.php`** — reads `/mc-data/config/meta.yaml`
- **`src/Model/GlobalMeta.php`** — `resourceLimits[]`, `voteThreshold`, `voteCooldown`, `serverEmptyGrace`
- **`src/Model/ServerMeta.php`** — add `voteThreshold`, `voteCooldown` per-server overrides
- **`src/Service/ServerMetaReader.php`** — add `voteThreshold`, `voteCooldown` fields
- **`src/Service/DockerClient.php`** — add `stopContainer()`, `getMemoryProfile()` helper
- **`src/Server/MinecraftMonitor.php`** — grace period detection on player leave; auto-stop check in `refreshStats()`; call `VoteManager::checkAndTrigger()` after confirmed stop
- **`src/Server/WebSocketServer.php`** — include vote state + grace state in `server_update`
- **`RedisClient.php`** — add `setCooldown()`, `hasCooldown()`, `clearVotesForServer()`, `setServerEmpty()`, `getServerEmpty()`, `clearServerEmpty()`

### Frontend
- **`assets/app.js`** — vote POST; reorder cards; grace countdown; update callouts from WebSocket
- **`templates/home/index.html.twig`** — medal badges, vote section, callout blocks, grace countdown, sorted cards

### Config/scripts
- **`mc-server-start.sh`** — injects `-e MEMORY_PROFILE=low|medium|high` ✅ (already updated)
- **`~/mc-server-manager-data/meta.yaml`** — new global config file (not in git, mounted at runtime)

---

## VoteManager service

```php
castVote(User $user, string $serverName): void
retractVote(User $user): void
getActiveVoteCount(string $serverName): int
getActiveVoters(string $serverName): array         // [{gamertag, avatarPath}]
getVoteRanking(): array                            // stopped servers sorted by active vote count desc
checkAndTrigger(string $serverName): void          // full check sequence, auto-start if all pass
triggerAutoStopIfNeeded(string $serverName): void  // called by MinecraftMonitor when grace expires
clearVotesForServer(string $serverName): void
getBlockingReason(string $serverName): ?string     // null | 'players' | 'grace' | 'resources'
getBlockingDetail(string $serverName): string      // human-readable for callout block
```

---

## ResourceBudgetChecker service

```php
canStart(string $candidateProfile): bool
getRunningProfiles(): array           // reads MEMORY_PROFILE from all running containers via DockerClient
isValidCombination(array $profiles, array $allowedCombination): bool
```

Uses actual Docker container state — containers in `stopping` or grace period still count as running.

---

## WebSocket server_update additions

```json
{
    "type": "server_update",
    "server": "server1",
    "data": {
        "...existing fields...",
        "memoryProfile": "high",
        "resourcesAvailable": false,
        "graceUntil": null,
        "votes": {
            "count": 2,
            "threshold": 3,
            "voters": [
                { "gamertag": "Player1", "avatarPath": "/avatars/player1.png" }
            ],
            "userHasVoted": true,
            "cooldown": false,
            "blockingReason": "resources",
            "blockingDetail": "This server requires high memory resources. Waiting for other servers to free up resources."
        }
    }
}
```

`graceUntil` — Unix timestamp when grace period ends, or `null` if not in grace period. JS uses this to render the countdown without polling.

---

## Full sequence diagrams

### Vote → auto-start (no resource conflict)

```
User clicks vote → POST /server/server1/vote
    → VoteManager::castVote(user, server1)
    → VoteManager::checkAndTrigger(server1)
        → active votes >= threshold ✅
        → no players on any running server ✅
        → no grace period active ✅
        → ResourceBudgetChecker::canStart(high) ✅
        → DockerClient::restartContainer(server1)
        → RedisClient::clearVotesForServer(server1)
        → RedisClient::setCooldown(server1)
    → WebSocketServer::broadcastServerUpdate(server1)
← Redirect to homepage
```

### Last player leaves → grace → auto-stop → auto-start

```
Last player leaves server2
    → MinecraftMonitor detects players = 0
    → RedisClient::setServerEmpty(server2, now())
    → WebSocketServer::broadcastServerUpdate(server2)  ← clients show countdown

    [60 seconds pass, no reconnect]

    → MinecraftMonitor::refreshStats() detects grace expired AND players still 0
    → VoteManager::triggerAutoStopIfNeeded(server2)
        → is any server waiting with votes >= threshold AND resource-blocked by server2? ✅
        → DockerClient::stopContainer(server2)
        → RedisClient::clearServerEmpty(server2)
    → WebSocketServer::broadcastServerUpdate(server2)  ← clients show stopping state

    [Docker stops container, MinecraftMonitor::scan() detects state = stopped]

    → RedisClient::setServer(server2, {running: false, ...})
    → VoteManager::checkAndTrigger(voteLeader)
        → ResourceBudgetChecker::canStart(leader.profile) ✅  ← server2 now stopped
        → DockerClient::restartContainer(voteLeader)
        → RedisClient::clearVotesForServer(voteLeader)
        → RedisClient::setCooldown(voteLeader)
    → WebSocketServer::broadcastServerUpdate(voteLeader)
```

### Player reconnects during grace period

```
Last player leaves server2
    → RedisClient::setServerEmpty(server2, now())
    → clients show countdown

    [30 seconds later — player reconnects]

    → MinecraftMonitor detects player join on server2
    → RedisClient::clearServerEmpty(server2)
    → WebSocketServer::broadcastServerUpdate(server2)  ← countdown disappears
    → no auto-stop, no vote trigger
```

---

## Testing strategy

### Layers

| Layer | Tool | Requires | Speed |
|---|---|---|---|
| Unit | PHPUnit | nothing | very fast |
| Integration | PHPUnit + Redis | running mc-redis | fast |
| Functional | Symfony WebTestCase | running mc-redis | fast |
| Browser | Symfony Panther | browser + mc-redis | slow — added later |

### Test folder structure

```
tests/
    Unit/
        VoteManagerTest.php
        ResourceBudgetCheckerTest.php
    Integration/
        VoteManagerIntegrationTest.php
    Functional/
        HomePageTest.php
        VoteControllerTest.php
    Fixtures/
        TestStateSeeder.php       ← shared by all layers AND dev controller
        MockDockerClient.php
src/
    Controller/
        Dev/
            TestStateController.php   ← dev environment only
```

### PHPUnit suites (`phpunit.xml`)

```xml
<testsuites>
    <testsuite name="unit">
        <directory>tests/Unit</directory>
    </testsuite>
    <testsuite name="integration">
        <directory>tests/Integration</directory>
        <directory>tests/Functional</directory>
    </testsuite>
</testsuites>
```

Run unit tests only: `php bin/phpunit --testsuite unit`
Run all: `php bin/phpunit`

---

## TestStateSeeder

Shared class used by both PHPUnit tests and the dev `TestStateController`.
Seeds Redis directly, bypassing `MinecraftMonitor` and `DockerClient`.

```php
class TestStateSeeder
{
    public function __construct(private readonly RedisClient $redis) {}

    public function seedServer(
        string  $name,
        bool    $running,
        string  $memoryProfile = 'medium',
        int     $players = 0,
        ?int    $graceUntil = null,   // Unix timestamp when grace period ends
        bool    $cooldown = false,
        int     $cooldownTtl = 300,
        ?int    $startedAt = null,
        ?int    $port = null,
        ?string $containerId = null,
    ): void

    public function seedVote(string $gamertag, string $serverName): void
    public function seedHeartbeat(string $gamertag, int $ttl = 120): void
    public function seedUser(string $username, string $gamertag, string $role): void
    public function reset(): void   // clears all vote/server/heartbeat/grace/cooldown keys
}
```

---

## Dev scenario controller

Only available in the `dev` environment. Registered via `routes.yaml`:

```yaml
when@dev:
    dev_test_state:
        resource: ../src/Controller/Dev/
        type: attribute
```

Routes: `GET /dev/seed/{scenario}` — seeds Redis then redirects to `/`.

### Scenario presets

#### `two-servers-voting`
Two stopped servers, both with votes. server1 has more votes than server2.
No resource conflicts. Verifies: vote counts, medal ordering (server1 on top), vote buttons.

```
server1: stopped, medium, 0 players, 2 votes (Player1 + Player2 with heartbeats)
server2: stopped, medium, 0 players, 1 vote  (Player3 with heartbeat)
```

#### `one-running-one-voted`
server1 is running with players. server2 is stopped with enough votes to start but blocked.
Verifies: server1 shows "running", server2 shows "waiting for players" callout.

```
server1: running, medium, 3 players, 0 votes
server2: stopped, medium, 3 votes (threshold = 3), blocked by server1 players
```

#### `grace-period`
server1 just went empty, grace period active with ~45 seconds remaining.
server2 has votes but is waiting.
Verifies: grace countdown on server1, "waiting for server to stop" callout on server2.

```
server1: running, medium, 0 players, graceUntil = now() + 45
server2: stopped, medium, 3 votes (threshold = 3)
```

#### `grace-expired-stopping`
server1 grace has expired, auto-stop triggered but container not yet stopped.
Verifies: server1 shows stopping state, server2 shows "waiting for server to stop".

```
server1: running, medium, 0 players, no grace key (expired), containerStatus = stopping
server2: stopped, medium, 3 votes (threshold = 3)
```

#### `resource-blocked`
server1 is running (high profile). server2 is stopped (medium profile) with votes.
Resource limits: `[[high:1, low:1], [medium:2]]` — high + medium not a valid combination.
Verifies: server2 shows resource blocked callout, card is greyed, voting still possible.

```
server1: running, high, 2 players, 0 votes
server2: stopped, medium, 2 votes (threshold = 3), resource blocked
```

#### `cooldown`
server1 was just auto-started, cooldown active.
Verifies: server1 vote button shows "Starting soon...", cooldown badge visible.

```
server1: running, medium, 0 players, cooldown active (TTL = 240s)
server2: stopped, medium, 1 vote
```

#### `multi-server-ranking`
Three stopped servers with different vote counts. Tests dynamic ordering and all three medal types.
Verifies: gold/silver/bronze medals, correct top-to-bottom ordering.

```
server1: stopped, medium, 1 vote  → should appear 3rd (bronze)
server2: stopped, medium, 3 votes → should appear 1st (gold crown)
server3: stopped, low,    2 votes → should appear 2nd (silver)
```

#### `anonymous`
No heartbeats — all users inactive. Tests that vote counts show as 0 even if votes exist in Redis.
Verifies: vote counts = 0, no voter avatars, no vote buttons for anonymous view.

```
server1: stopped, medium, votes exist in Redis but no active heartbeats
server2: stopped, medium, votes exist in Redis but no active heartbeats
```

#### `reset`
Clears all seeded state from Redis. Real `MinecraftMonitor` data repopulates on next scan cycle (within 30s) or container restart.

---

## Unit test examples

### ResourceBudgetCheckerTest

```php
public function testHighBlockedByMedium(): void
{
    $checker = $this->makeChecker(
        limits: [['high' => 1, 'low' => 1], ['medium' => 2]],
        running: ['medium']
    );
    $this->assertFalse($checker->canStart('high'));
}

public function testLowAllowedWithHigh(): void
{
    $checker = $this->makeChecker(
        limits: [['high' => 1, 'low' => 1], ['medium' => 2]],
        running: ['high']
    );
    $this->assertTrue($checker->canStart('low'));
}

public function testMediumAllowedWhenEmpty(): void
{
    $checker = $this->makeChecker(
        limits: [['high' => 1, 'low' => 1], ['medium' => 2]],
        running: []
    );
    $this->assertTrue($checker->canStart('medium'));
}

public function testTwoMediumAllowed(): void
{
    $checker = $this->makeChecker(
        limits: [['high' => 1, 'low' => 1], ['medium' => 2]],
        running: ['medium']
    );
    $this->assertTrue($checker->canStart('medium'));
}

public function testThreeMediumBlocked(): void
{
    $checker = $this->makeChecker(
        limits: [['high' => 1, 'low' => 1], ['medium' => 2]],
        running: ['medium', 'medium']
    );
    $this->assertFalse($checker->canStart('medium'));
}
```

### VoteManagerTest

```php
public function testAutoStartNotTriggeredDuringGracePeriod(): void
{
    $docker = $this->createMock(DockerClient::class);
    $docker->expects($this->never())->method('restartContainer');

    // server1 running but in grace period
    $this->seeder->seedServer('server1', running: true, players: 0, graceUntil: time() + 30);
    $this->seeder->seedServer('server2', running: false);
    $this->seeder->seedVote('Player1', 'server2');
    $this->seeder->seedHeartbeat('Player1');

    $this->voteManager->checkAndTrigger('server2');
}

public function testAutoStartTriggeredWhenAllClear(): void
{
    $docker = $this->createMock(DockerClient::class);
    $docker->expects($this->once())->method('restartContainer');

    $this->seeder->seedServer('server1', running: false, memoryProfile: 'medium');
    $this->seeder->seedVote('Player1', 'server1');
    $this->seeder->seedVote('Player2', 'server1');
    $this->seeder->seedVote('Player3', 'server1');
    $this->seeder->seedHeartbeat('Player1');
    $this->seeder->seedHeartbeat('Player2');
    $this->seeder->seedHeartbeat('Player3');

    $this->voteManager->checkAndTrigger('server1');
}

public function testAutoStopNotTriggeredIfNoServerWaiting(): void
{
    $docker = $this->createMock(DockerClient::class);
    $docker->expects($this->never())->method('stopContainer');

    // server1 empty, grace expired, but no other server has votes
    $this->seeder->seedServer('server1', running: true, players: 0);
    // no votes seeded

    $this->voteManager->triggerAutoStopIfNeeded('server1');
}
```
