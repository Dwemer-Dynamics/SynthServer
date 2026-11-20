# GameRpc

Synchronous, bounded calls from the server to the running game (chevo: `GameRpcClient` over the notify-server). Wire text:
`protocol/v2/README.md` ("Game RPC over the wake-up channel"), schema `protocol/v2/schemas/rpc-frame.schema.json`.

## Contract

- `GameRpcClient::call($method, $params, $timeoutMs)` returns the game's result array or throws `GameNotConnectedException`,
  `GameTimeoutException`, `GameCallFailedException` (`errorCode`) or `GameRpcException`.
- `GameRpcMethods`: the closed method list (`ping`, `session.info`, `inventory.player`, `world.nearby_items`), parameter bounds and
  the closed error codes. A method on the list is only a request the game may answer; the client answers `method_unavailable` for
  a feature it does not have.
- `GameRpc::forSession($sessionId)`: the default client. It is `NotifyGameRpcClient` only while that session's game is connected on
  the channel, otherwise `UnwiredGameRpcClient` (every call throws `GameNotConnectedException`).

## How a call travels

1. The client lists `notify.wake` and `rpc.game` in a v2 `init` (INI opt-in, off by default). The init status carries
   `rpc_game: {"token"}` only when the notification channel runs: `RpcLoopback::mintSessionToken()` makes a random 256-bit token and
   registers it with the channel over the loopback listener (`POST /rpc/session`).
2. The client's wake-up WebSocket sends `hello` with its session id and the token. `RpcBridge` binds the connection to the session
   (one per session, the newest wins). A wrong token closes the connection; a client that never sends `hello` stays a plain
   wake-up listener.
3. `NotifyGameRpcClient::call()` posts `/rpc/call` to the loopback listener. `RpcBridge` sends a `request` frame and keeps the
   caller's socket open until the game's `response`, the deadline (`timeout`), or the connection ends (`not_connected`).

## Bounds

At most 8 calls wait at once (`busy` beyond that), 16 registered sessions, tokens expire after 24 hours, frames are at most 16 KiB,
timeouts are 100 to 10000 ms, parameters are checked against the method before anything is sent, and a response is accepted only
for a request this bridge sent on that same connection. A malformed response closes the connection.

## Not done

- The client answers `ping` and `session.info` only. `inventory.player` and `world.nearby_items` need a game-thread provider and are
  answered `method_unavailable` until one exists; nothing on the server calls them yet.
- No server code calls `GameRpc::forSession()` yet; prompts do not use game calls.
- The token and the channel are plain `ws://` and `http://`: the token proves the connection belongs to the session that was given
  it, it is not a secret against someone who can read the session's HTTP traffic. A remote installation should keep the channel
  port on its private network.
- In-game behaviour is untested.
