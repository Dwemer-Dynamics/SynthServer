# GameRpc

Contract for synchronous calls from the server to the running game (chevo: `GameRpcClient` over the notify-server).

SYNTH has no such channel today. `managers/Notification` only sends a wake-up hint to the plugin and never receives an
answer; the plugin delivers results over the ordinary HTTP routes. So this folder defines the contract and nothing else:

- `GameRpcClient::call($method, $params, $timeoutMs)` returns the game's result array or throws one of
  `GameNotConnectedException`, `GameTimeoutException`, `GameCallFailedException` (`errorCode`) or `GameRpcException`.
- `UnwiredGameRpcClient` is the only implementation and always throws `GameNotConnectedException`.

Nothing calls it yet. A real client (and a plugin-side `rpc.request` handler) is a separate protocol change; add it
behind this interface. No methods are defined until the plugin answers them.
