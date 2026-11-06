// SPDX-License-Identifier: GPL-3.0-only
using System.Text.Json;
using System.Text.Json.Nodes;
using Synth.Launcher;

var cases = new List<(string Name, Func<Task> Run)>();
void Test(string name, Func<Task> run) => cases.Add((name, run));
void Sync(string name, Action run) => Test(name, () => { run(); return Task.CompletedTask; });

Sync("default configuration and canonical dashboard", () =>
{
    var config = LauncherConfig.Parse("{}");
    Expect(config.Distro == "Ubuntu" && config.Project == "synth-stu" && config.StartTimeoutSeconds == 90);
    Expect(config.HealthUri.AbsoluteUri == "http://127.0.0.1:8087/Synthserver/health.php");
    Expect(config.DashboardUri.AbsoluteUri == "http://127.0.0.1:8087/Synthserver/ui/home.php");
    Expect(config.SpeechHealthUri.AbsoluteUri == "http://127.0.0.1:9876/health");
});
Sync("configuration accepts explicit local F4SE path", () =>
    Expect(LauncherConfig.Parse("{\"GameExecutable\":\"C:\\\\Games\\\\Fallout 4\\\\f4se_loader.exe\"}").GameExecutable is not null));

foreach (var (name, json) in new (string, string)[]
{
    ("nonobject", "[]"), ("malformed", "{"), ("unknown script key", "{\"StartScript\":\"secret-value\"}"),
    ("case mismatch", "{\"distro\":\"Ubuntu\"}"), ("duplicate field", "{\"Project\":\"wrong\",\"Project\":\"synth-stu\"}"),
    ("option token", "{\"Distro\":\"--exec\"}"), ("shell token", "{\"Project\":\"name; touch x\"}"),
    ("newline token", "{\"Network\":\"net\\nsecret\"}"), ("numeric string", "{\"StartTimeoutSeconds\":\"90\"}"),
    ("short timeout", "{\"StartTimeoutSeconds\":4}"), ("long timeout", "{\"StartTimeoutSeconds\":301}"),
    ("duplicate containers", "{\"SpeechContainer\":\"synth-stu-db\"}"),
    ("duplicate volumes", "{\"ServerVolume\":\"synth-stu-db-data\"}"),
    ("remote host", "{\"BackendBaseUrl\":\"http://example.com/Synthserver/\"}"),
    ("localhost spelling", "{\"BackendBaseUrl\":\"http://localhost:8087/Synthserver/\"}"),
    ("short IP spelling", "{\"BackendBaseUrl\":\"http://127.1:8087/Synthserver/\"}"),
    ("IPv6 spelling", "{\"BackendBaseUrl\":\"http://[::1]:8087/Synthserver/\"}"),
    ("URL credentials", "{\"BackendBaseUrl\":\"http://user:secret-value@127.0.0.1:8087/Synthserver/\"}"),
    ("URL query", "{\"BackendBaseUrl\":\"http://127.0.0.1:8087/Synthserver/?secret-value\"}"),
    ("URL fragment", "{\"SpeechHealthUrl\":\"http://127.0.0.1:9876/health#secret-value\"}"),
    ("HTTPS URL", "{\"SpeechHealthUrl\":\"https://127.0.0.1:9876/health\"}"),
    ("missing base slash", "{\"BackendBaseUrl\":\"http://127.0.0.1:8087/Synthserver\"}"),
    ("UNC game path", "{\"GameExecutable\":\"\\\\\\\\server\\\\f4se_loader.exe\"}"),
    ("arbitrary executable", "{\"GameExecutable\":\"C:\\\\Windows\\\\cmd.exe\"}"),
    ("game arguments", "{\"GameExecutable\":\"C:\\\\Games\\\\f4se_loader.exe -flag\"}"),
    ("relative game path", "{\"GameExecutable\":\"f4se_loader.exe\"}")
})
{
    Sync("reject config: " + name, () =>
    {
        var error = Throws<LauncherException>(() => LauncherConfig.Parse(json));
        Expect(!error.Message.Contains("secret-value", StringComparison.Ordinal));
    });
}
Sync("configuration read is size bounded", () =>
    Throws<LauncherException>(() => LauncherConfig.Parse("{\"Project\":\"" + new string('s', 17000) + "\"}")));
Sync("missing config path produces generic error", () =>
{
    var error = Throws<LauncherException>(() => LauncherConfig.Load(Path.Combine(Path.GetTempPath(), Guid.NewGuid() + "-secret-value.json")));
    Expect(!error.Message.Contains("secret-value", StringComparison.Ordinal));
});

Test("read-only check is ready and never starts", async () =>
{
    var f = new Fixture();
    var state = await f.Core.CheckAsync(CancellationToken.None);
    Expect(state.Ready && state.BackendReady && state.SpeechReady);
    Expect(f.Host.Calls.Count == 6 && f.Host.Starts.Count == 0);
    AssertCommandAllowlist(f.Host.Calls);
    Expect(f.Health.Calls.Select(u => u.AbsoluteUri).SequenceEqual(new[]
    { "http://127.0.0.1:8087/Synthserver/health.php", "http://127.0.0.1:9876/health" }));
});
Test("already running start is idempotent", async () =>
{
    var f = new Fixture();
    Expect((await f.Core.StartAsync(null, CancellationToken.None)).Ready);
    Expect((await f.Core.StartAsync(null, CancellationToken.None)).Ready);
    Expect(f.Host.Starts.Count == 0);
});
foreach (var containerName in new[] { "synth-stu-db", "synth-stu-server", "synth-stu-whisper" })
{
    Test("check cannot report a stopped container ready: " + containerName, async () =>
    {
        var f = new Fixture();
        f.Container(containerName)["Running"] = false;
        f.Container(containerName)["State"] = "exited";
        var state = await f.Core.CheckAsync(CancellationToken.None);
        Expect(!state.Ready && f.Host.Starts.Count == 0);
        if (containerName == "synth-stu-whisper") Expect(state.BackendReady && !state.SpeechReady);
        else Expect(!state.BackendReady && state.SpeechReady);
    });
}
Test("stopped containers start by immutable ID in order", async () =>
{
    var f = new Fixture(false);
    Expect((await f.Core.StartAsync(null, CancellationToken.None)).Ready);
    Expect(f.Host.Starts.SequenceEqual(new[] { Fixture.DbId, Fixture.ServerId, Fixture.SpeechId }));
    Expect(f.Host.Calls.Take(6).All(c => c[5] == "inspect"));
    AssertCommandAllowlist(f.Host.Calls);
    Expect((await f.Core.StartAsync(null, CancellationToken.None)).Ready);
    Expect(f.Host.Starts.Count == 3);
});
Test("mixed running state only starts stopped services", async () =>
{
    var f = new Fixture(false);
    f.Container("synth-stu-db")["Running"] = true;
    f.Container("synth-stu-db")["State"] = "running";
    Expect((await f.Core.StartAsync(null, CancellationToken.None)).Ready);
    Expect(f.Host.Starts.SequenceEqual(new[] { Fixture.ServerId, Fixture.SpeechId }));
});

foreach (var key in new[] { "container:synth-stu-db", "container:synth-stu-server", "container:synth-stu-whisper",
             "volume:synth-stu-db-data", "volume:synth-stu-server-data", "network:synth-stu-net" })
{
    Test("ownership fence: " + key, async () =>
    {
        var f = new Fixture(false);
        f.Host.Resources[key]["Project"] = "unrelated-project";
        var state = await f.Core.StartAsync(null, CancellationToken.None);
        Expect(!state.Ready && f.Host.Starts.Count == 0 && f.Health.Calls.Count == 0);
    });
    Test("missing fence: " + key, async () =>
    {
        var f = new Fixture(false);
        f.Host.Resources.Remove(key);
        var state = await f.Core.StartAsync(null, CancellationToken.None);
        Expect(!state.Ready && f.Host.Starts.Count == 0 && f.Health.Calls.Count == 0);
        Expect(!state.Message.Contains("secret-value", StringComparison.Ordinal));
    });
}

var mutations = new List<(string Name, Action<Fixture> Change)>
{
    ("database wrong volume", f => f.Container("synth-stu-db")["Mounts"]![0]!["Name"] = "wrong"),
    ("database wrong mount destination", f => f.Container("synth-stu-db")["Mounts"]![0]!["Destination"] = "/tmp/data"),
    ("backend bind mount", f => f.Container("synth-stu-server")["Mounts"]![0]!["Type"] = "bind"),
    ("backend wrong volume", f => f.Container("synth-stu-server")["Mounts"]![0]!["Name"] = "wrong"),
    ("backend duplicate mount", f => ((JsonArray)f.Container("synth-stu-server")["Mounts"]!).Add(f.Container("synth-stu-server")["Mounts"]![0]!.DeepClone())),
    ("named ID is not immutable", f => f.Container("synth-stu-server")["Id"] = "synth-stu-server"),
    ("duplicate container IDs", f => f.Container("synth-stu-server")["Id"] = Fixture.DbId),
    ("network wrong immutable ID", f => f.Host.Resources["network:synth-stu-net"]["Id"] = "wrong"),
    ("database wrong network", f => f.Container("synth-stu-db")["NetworkMode"] = "default"),
    ("speech wrong network", f => f.Container("synth-stu-whisper")["NetworkMode"] = "host"),
    ("database wrong network membership", f => f.Container("synth-stu-db")["Networks"]!["synth-stu-net"]!["NetworkID"] = Fixture.ServerId),
    ("additional speech network", f => f.Container("synth-stu-whisper")["Networks"]!["unexpected"] = new JsonObject { ["NetworkID"] = Fixture.NetworkId }),
    ("backend wrong database identity", f => f.Container("synth-stu-server")["NetworkMode"] = "container:" + Fixture.SpeechId),
    ("public backend binding", f => f.Container("synth-stu-db")["Ports"]!["80/tcp"]![0]!["HostIp"] = "0.0.0.0"),
    ("public speech binding", f => f.Container("synth-stu-whisper")["Ports"]!["9876/tcp"]![0]!["HostIp"] = "::"),
    ("wrong backend port", f => f.Container("synth-stu-db")["Ports"]!["80/tcp"]![0]!["HostPort"] = "8088"),
    ("published database port", f => f.Container("synth-stu-db")["Ports"]!["5432/tcp"] = Fixture.Bindings(5432)),
    ("backend own port binding", f => f.Container("synth-stu-server")["Ports"]!["80/tcp"] = Fixture.Bindings(8087)),
    ("missing backend binding", f => f.Container("synth-stu-db")["Ports"] = new JsonObject()),
    ("missing speech binding", f => f.Container("synth-stu-whisper")["Ports"] = new JsonObject()),
    ("paused database", f => f.Container("synth-stu-db")["State"] = "paused"),
    ("inconsistent running state", f => f.Container("synth-stu-db")["Running"] = true)
};
foreach (var mutation in mutations)
{
    Test("topology fence: " + mutation.Name, async () =>
    {
        var f = new Fixture(false);
        mutation.Change(f);
        Expect(!(await f.Core.StartAsync(null, CancellationToken.None)).Ready);
        Expect(f.Host.Starts.Count == 0 && f.Health.Calls.Count == 0);
    });
}

Test("duplicate metadata properties reject before start", async () =>
{
    var f = new Fixture(false);
    f.Host.RawOverride = "{\"Project\":\"unrelated\",\"Project\":\"synth-stu\"}";
    Expect(!(await f.Core.StartAsync(null, CancellationToken.None)).Ready && f.Host.Starts.Count == 0);
});
Test("oversized metadata output rejects before start", async () =>
{
    var f = new Fixture(false);
    f.Host.RawOverride = new string('x', 65537);
    Expect(!(await f.Core.StartAsync(null, CancellationToken.None)).Ready && f.Host.Starts.Count == 0);
});
Test("metadata command timeout rejects before start", async () =>
{
    var f = new Fixture(false);
    f.Host.TimedOut = true;
    Expect(!(await f.Core.StartAsync(null, CancellationToken.None)).Ready && f.Host.Starts.Count == 0);
});
Test("start failure prevents subsequent services and hides output", async () =>
{
    var f = new Fixture(false);
    f.Host.FailStart = Fixture.DbId;
    var status = await f.Core.StartAsync(null, CancellationToken.None);
    Expect(!status.Ready && f.Host.Starts.SequenceEqual(new[] { Fixture.DbId }));
    Expect(!status.Message.Contains("secret-value", StringComparison.Ordinal));
});
foreach (var (backend, speech) in new[] { (true, true), (true, false), (false, true), (false, false) })
{
    Test($"independent readiness: backend={backend}, speech={speech}", async () =>
    {
        var f = new Fixture();
        if (!backend) f.Health.Backend = null;
        if (!speech) f.Health.Speech = null;
        var state = await f.Core.CheckAsync(CancellationToken.None);
        Expect(state.BackendReady == backend && state.SpeechReady == speech && state.Ready == (backend && speech));
    });
}
foreach (var (name, json) in new (string, string)[]
{
    ("wrong service", "{\"service\":\"other\",\"status\":\"ok\",\"database\":true,\"schema\":true,\"background_processor\":true}"),
    ("wrong status", Fixture.BackendReady.Replace("\"ok\"", "\"failed\"", StringComparison.Ordinal)),
    ("database false", Fixture.BackendReady.Replace("\"database\":true", "\"database\":false", StringComparison.Ordinal)),
    ("schema false", Fixture.BackendReady.Replace("\"schema\":true", "\"schema\":false", StringComparison.Ordinal)),
    ("worker false", Fixture.BackendReady.Replace("\"background_processor\":true", "\"background_processor\":false", StringComparison.Ordinal)),
    ("string boolean", Fixture.BackendReady.Replace("\"schema\":true", "\"schema\":\"true\"", StringComparison.Ordinal)),
    ("duplicate property", Fixture.BackendReady.Replace("\"status\":\"ok\"", "\"status\":\"bad\",\"status\":\"ok\"", StringComparison.Ordinal)),
    ("nonobject", "[]")
})
{
    Test("backend health rejects: " + name, async () =>
    {
        var f = new Fixture(); f.Health.Backend = json;
        var state = await f.Core.CheckAsync(CancellationToken.None);
        Expect(!state.BackendReady && state.SpeechReady);
    });
}
foreach (var (name, json) in new (string, string)[]
{
    ("wrong service", Fixture.SpeechReady.Replace("SYNTH Whisper", "other", StringComparison.Ordinal)),
    ("loading model", Fixture.SpeechReady.Replace("ready", "loading", StringComparison.Ordinal)),
    ("microphone capture true", Fixture.SpeechReady.Replace("false", "true", StringComparison.Ordinal)),
    ("microphone capture absent", "{\"service\":\"SYNTH Whisper\",\"status\":\"ready\"}"),
    ("microphone string", Fixture.SpeechReady.Replace("false", "\"false\"", StringComparison.Ordinal))
})
{
    Test("speech health rejects: " + name, async () =>
    {
        var f = new Fixture(); f.Health.Speech = json;
        var state = await f.Core.CheckAsync(CancellationToken.None);
        Expect(state.BackendReady && !state.SpeechReady);
    });
}
Test("readiness polls until both services are ready", async () =>
{
    var f = new Fixture(); f.Health.UnreadyRounds = 1;
    Expect((await f.Core.StartAsync(null, CancellationToken.None)).Ready);
    Expect(f.Health.Calls.Count == 4 && f.Host.Starts.Count == 0);
});
Test("total readiness timeout is bounded", async () =>
{
    var f = new Fixture(config: LauncherConfig.Defaults() with { StartTimeoutSeconds = 5 });
    f.Health.Backend = null;
    var watch = System.Diagnostics.Stopwatch.StartNew();
    var state = await f.Core.StartAsync(null, CancellationToken.None);
    Expect(!state.Ready && state.Message.Contains("timeout", StringComparison.Ordinal));
    Expect(watch.Elapsed < TimeSpan.FromSeconds(7));
});
Test("caller cancellation interrupts an uncooperative executor", async () =>
{
    var f = new Fixture(false); f.Host.Hang = true;
    using var token = new CancellationTokenSource(TimeSpan.FromMilliseconds(50));
    await ThrowsAsync<OperationCanceledException>(() => f.Core.StartAsync(null, token.Token));
    Expect(f.Host.Starts.Count == 0);
});
Test("caller cancellation interrupts an uncooperative health adapter", async () =>
{
    var f = new Fixture(); f.Health.Hang = true;
    using var token = new CancellationTokenSource(TimeSpan.FromMilliseconds(50));
    await ThrowsAsync<OperationCanceledException>(() => f.Core.CheckAsync(token.Token));
    Expect(f.Host.Starts.Count == 0);
});
foreach (var error in new Exception[] { new IOException("secret-value"), new LauncherException("secret-value"), new InvalidOperationException("secret-value") })
{
    Test("adapter errors are generic: " + error.GetType().Name, async () =>
    {
        var f = new Fixture(); f.Host.Error = error;
        var state = await f.Core.CheckAsync(CancellationToken.None);
        Expect(!state.Ready && !state.Message.Contains("secret-value", StringComparison.Ordinal));
    });
}
Test("process adapter refuses arbitrary shell without executing", async () =>
    await ThrowsAsync<LauncherException>(() => new WindowsHostExecutor().ExecuteAsync(
        new[] { "-d", "Ubuntu", "--exec", "sh", "-c", "secret-value" }, TimeSpan.FromSeconds(1), CancellationToken.None)));
Test("HTTP adapter refuses remote health before networking", async () =>
    await ThrowsAsync<LauncherException>(() => new LoopbackJsonHealthClient().GetAsync(
        new Uri("http://example.com/health"), TimeSpan.FromSeconds(1), CancellationToken.None)));

var failed = 0;
foreach (var test in cases)
{
    try { await test.Run(); Console.WriteLine("PASS " + test.Name); }
    catch (Exception error) { failed++; Console.WriteLine("FAIL " + test.Name + " (" + error.GetType().Name + ")"); }
}
Console.WriteLine($"Launcher core: {cases.Count - failed}/{cases.Count} synthetic tests passed. No live resources were accessed or changed.");
return failed == 0 ? 0 : 1;

static void Expect(bool condition) { if (!condition) throw new InvalidOperationException("Assertion failed."); }
static T Throws<T>(Action action) where T : Exception
{
    try { action(); } catch (T exception) { return exception; }
    throw new InvalidOperationException("Expected exception.");
}
static async Task<T> ThrowsAsync<T>(Func<Task> action) where T : Exception
{
    try { await action(); } catch (T exception) { return exception; }
    throw new InvalidOperationException("Expected exception.");
}
static void AssertCommandAllowlist(IEnumerable<string[]> calls)
{
    foreach (var call in calls)
    {
        Expect(call[0] == "-d" && call[1] == "Ubuntu" && call[2] == "--exec" && call[3] == "docker");
        if (call[4] == "start") { Expect(call.Length == 6 && call[5].Length == 64); continue; }
        Expect(call.Length == 9 && call[5] == "inspect" && call[6] == "--format");
        Expect(call[4] is "container" or "volume" or "network");
        Expect(call[7].Contains("\"project\"", StringComparison.Ordinal));
        Expect(!call[7].Contains(".Env", StringComparison.Ordinal) && !call[7].Contains(".Cmd", StringComparison.Ordinal));
    }
}

sealed class Fixture
{
    public static readonly string DbId = new('a', 64), ServerId = new('b', 64), SpeechId = new('c', 64), NetworkId = new('d', 64);
    public const string BackendReady = "{\"service\":\"SYNTH Server\",\"status\":\"ok\",\"database\":true,\"schema\":true,\"background_processor\":true}";
    public const string SpeechReady = "{\"service\":\"SYNTH Whisper\",\"status\":\"ready\",\"model\":\"small.en\",\"device\":\"cpu\",\"compute_type\":\"int8\",\"microphone_capture\":false}";
    public FakeHost Host { get; } = new();
    public FakeHealth Health { get; } = new();
    public LauncherCore Core { get; }
    public Fixture(bool running = true, LauncherConfig? config = null)
    {
        Host.Resources["container:synth-stu-db"] = MakeContainer(DbId, running, "synth-stu-net", "synth-stu-db-data", "/var/lib/postgresql/data", "80/tcp", 8087);
        Host.Resources["container:synth-stu-server"] = MakeContainer(ServerId, running, "container:" + DbId, "synth-stu-server-data", "/var/www/html/Synthserver", null, 0);
        Host.Resources["container:synth-stu-whisper"] = MakeContainer(SpeechId, running, "synth-stu-net", null, null, "9876/tcp", 9876);
        Host.Resources["volume:synth-stu-db-data"] = new JsonObject { ["Name"] = "synth-stu-db-data", ["Project"] = "synth-stu" };
        Host.Resources["volume:synth-stu-server-data"] = new JsonObject { ["Name"] = "synth-stu-server-data", ["Project"] = "synth-stu" };
        Host.Resources["network:synth-stu-net"] = new JsonObject { ["Name"] = "synth-stu-net", ["Project"] = "synth-stu", ["Id"] = NetworkId };
        Core = new LauncherCore(config ?? LauncherConfig.Defaults(), Host, Health);
    }
    public JsonObject Container(string name) => Host.Resources["container:" + name];
    public static JsonArray Bindings(int port) => new(new JsonObject { ["HostIp"] = "127.0.0.1", ["HostPort"] = port.ToString(System.Globalization.CultureInfo.InvariantCulture) });
    private static JsonObject MakeContainer(string id, bool running, string network, string? volume, string? destination, string? port, int hostPort)
    {
        var mounts = new JsonArray();
        if (volume is not null) mounts.Add(new JsonObject { ["Type"] = "volume", ["Name"] = volume, ["Destination"] = destination });
        var ports = new JsonObject();
        if (port is not null) ports[port] = Bindings(hostPort);
        return new JsonObject
        {
            ["Id"] = id, ["Project"] = "synth-stu", ["Running"] = running, ["State"] = running ? "running" : "exited",
            ["NetworkMode"] = network, ["Mounts"] = mounts, ["Ports"] = ports,
            ["Networks"] = network == "synth-stu-net" ? new JsonObject { ["synth-stu-net"] = new JsonObject { ["NetworkID"] = NetworkId } } : new JsonObject()
        };
    }
}

sealed class FakeHost : IHostExecutor
{
    public Dictionary<string, JsonObject> Resources { get; } = new(StringComparer.Ordinal);
    public List<string[]> Calls { get; } = [];
    public List<string> Starts { get; } = [];
    public string? RawOverride { get; set; }
    public string? FailStart { get; set; }
    public Exception? Error { get; set; }
    public bool Hang { get; set; }
    public bool TimedOut { get; set; }
    public Task<HostResult> ExecuteAsync(IReadOnlyList<string> arguments, TimeSpan timeout, CancellationToken cancellationToken)
    {
        cancellationToken.ThrowIfCancellationRequested();
        Calls.Add(arguments.ToArray());
        if (Error is not null) return Task.FromException<HostResult>(Error);
        if (Hang) return new TaskCompletionSource<HostResult>().Task;
        if (TimedOut) return Task.FromResult(new HostResult(-1, "secret-value", true));
        if (arguments[4] == "start")
        {
            var id = arguments[5]; Starts.Add(id);
            if (id == FailStart) return Task.FromResult(new HostResult(1, "secret-value"));
            var container = Resources.Values.Single(r => r["Id"]?.GetValue<string>() == id);
            container["Running"] = true; container["State"] = "running";
            return Task.FromResult(new HostResult(0, id));
        }
        if (RawOverride is not null) return Task.FromResult(new HostResult(0, RawOverride));
        return Task.FromResult(Resources.TryGetValue(arguments[4] + ":" + arguments[8], out var resource)
            ? new HostResult(0, resource.ToJsonString()) : new HostResult(1, "secret-value"));
    }
}

sealed class FakeHealth : IJsonHealthClient
{
    public string? Backend { get; set; } = Fixture.BackendReady;
    public string? Speech { get; set; } = Fixture.SpeechReady;
    public List<Uri> Calls { get; } = [];
    public int UnreadyRounds { get; set; }
    public bool Hang { get; set; }
    public Task<JsonDocument?> GetAsync(Uri uri, TimeSpan timeout, CancellationToken cancellationToken)
    {
        cancellationToken.ThrowIfCancellationRequested(); Calls.Add(uri);
        if (Hang) return new TaskCompletionSource<JsonDocument?>().Task;
        var json = uri.Port == 8087 ? Backend : Speech;
        if (UnreadyRounds > 0)
        {
            json = null;
            if (uri.Port == 9876) UnreadyRounds--;
        }
        return Task.FromResult(json is null ? null : JsonDocument.Parse(json));
    }
}
