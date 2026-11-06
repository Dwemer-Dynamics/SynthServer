// SPDX-License-Identifier: GPL-3.0-only
using System.Diagnostics;
using System.Net;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace Synth.Launcher;

public sealed record LauncherConfig
{
    public string Distro { get; init; } = "Ubuntu";
    public string Project { get; init; } = "synth-stu";
    public string DatabaseContainer { get; init; } = "synth-stu-db";
    public string ServerContainer { get; init; } = "synth-stu-server";
    public string SpeechContainer { get; init; } = "synth-stu-whisper";
    public string DatabaseVolume { get; init; } = "synth-stu-db-data";
    public string ServerVolume { get; init; } = "synth-stu-server-data";
    public string Network { get; init; } = "synth-stu-net";
    public string BackendBaseUrl { get; init; } = "http://127.0.0.1:8087/Synthserver/";
    public string SpeechHealthUrl { get; init; } = "http://127.0.0.1:9876/health";
    public string? GameExecutable { get; init; }
    public int StartTimeoutSeconds { get; init; } = 90;
    public Uri BackendHealthUri => new(new Uri(BackendBaseUrl), "health.php");
    public Uri HealthUri => BackendHealthUri;
    public Uri DashboardUri => new(new Uri(BackendBaseUrl), "ui/home.php");
    public Uri SpeechHealthUri => new(SpeechHealthUrl);

    public static LauncherConfig Defaults() => new();

    public static LauncherConfig Load(string path)
    {
        try
        {
            using var stream = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.Read);
            if (stream.Length > 16384) throw InvalidConfig();
            using var reader = new StreamReader(stream, new UTF8Encoding(false, true), true);
            return Parse(reader.ReadToEnd());
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException or DecoderFallbackException)
        {
            throw new LauncherException("The local launcher configuration could not be read.");
        }
    }

    public static LauncherConfig Parse(string json)
    {
        try
        {
            if (Encoding.UTF8.GetByteCount(json) > 16384) throw InvalidConfig();
            using var doc = JsonDocument.Parse(json, new JsonDocumentOptions { MaxDepth = 4 });
            if (doc.RootElement.ValueKind != JsonValueKind.Object) throw InvalidConfig();
            var config = Defaults();
            var seen = new HashSet<string>(StringComparer.Ordinal);
            foreach (var field in doc.RootElement.EnumerateObject())
            {
                if (!seen.Add(field.Name)) throw InvalidConfig();
                if (field.Name == nameof(StartTimeoutSeconds))
                {
                    if (!field.Value.TryGetInt32(out var seconds)) throw InvalidConfig();
                    config = config with { StartTimeoutSeconds = seconds };
                    continue;
                }
                if (field.Name == nameof(GameExecutable) && field.Value.ValueKind == JsonValueKind.Null)
                {
                    config = config with { GameExecutable = null };
                    continue;
                }
                if (field.Value.ValueKind != JsonValueKind.String) throw InvalidConfig();
                var value = field.Value.GetString()!;
                config = field.Name switch
                {
                    nameof(Distro) => config with { Distro = value },
                    nameof(Project) => config with { Project = value },
                    nameof(DatabaseContainer) => config with { DatabaseContainer = value },
                    nameof(ServerContainer) => config with { ServerContainer = value },
                    nameof(SpeechContainer) => config with { SpeechContainer = value },
                    nameof(DatabaseVolume) => config with { DatabaseVolume = value },
                    nameof(ServerVolume) => config with { ServerVolume = value },
                    nameof(Network) => config with { Network = value },
                    nameof(BackendBaseUrl) => config with { BackendBaseUrl = value },
                    nameof(SpeechHealthUrl) => config with { SpeechHealthUrl = value },
                    nameof(GameExecutable) => config with { GameExecutable = value },
                    _ => throw InvalidConfig()
                };
            }
            config.Validate();
            return config;
        }
        catch (Exception exception) when (exception is JsonException or InvalidOperationException or ArgumentException)
        {
            throw InvalidConfig();
        }
    }

    public void Validate()
    {
        foreach (var value in new[] { Distro, Project, DatabaseContainer, ServerContainer, SpeechContainer,
                     DatabaseVolume, ServerVolume, Network })
            if (!SafeIdentifier(value)) throw InvalidConfig();
        if (new[] { DatabaseContainer, ServerContainer, SpeechContainer }.Distinct(StringComparer.Ordinal).Count() != 3 ||
            DatabaseVolume == ServerVolume || StartTimeoutSeconds is < 5 or > 300) throw InvalidConfig();
        ValidateLoopbackUrl(BackendBaseUrl, true);
        ValidateLoopbackUrl(SpeechHealthUrl, false);
        if (GameExecutable is not null && (GameExecutable.Length > 1024 ||
            GameExecutable.Any(char.IsControl) || !Path.IsPathFullyQualified(GameExecutable) ||
            !Regex.IsMatch(GameExecutable, @"\A[A-Za-z]:[\\/]", RegexOptions.CultureInvariant) ||
            GameExecutable.Count(c => c == ':') != 1 ||
            !string.Equals(Path.GetFileName(GameExecutable), "f4se_loader.exe", StringComparison.OrdinalIgnoreCase)))
            throw InvalidConfig();
    }

    internal static bool SafeIdentifier(string? value) => value is not null &&
        Regex.IsMatch(value, @"\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z", RegexOptions.CultureInvariant);

    internal static void ValidateLoopbackUrl(string value, bool trailingSlash)
    {
        // Reject alternative spellings and canonicalization of a nonliteral address.
        if (value is null || value.Length > 2048 || value.Any(char.IsControl) || value.Contains('\\') ||
            !Regex.IsMatch(value, @"\Ahttp://127\.0\.0\.1(?::[0-9]{1,5})?/", RegexOptions.CultureInvariant) ||
            !Uri.TryCreate(value, UriKind.Absolute, out var uri) || uri.Scheme != "http" ||
            uri.Host != "127.0.0.1" || uri.UserInfo.Length != 0 || uri.Query.Length != 0 || uri.Fragment.Length != 0 ||
            uri.Port is < 1 or > 65535 || (trailingSlash && !value.EndsWith('/')))
            throw InvalidConfig();
    }

    private static LauncherException InvalidConfig() => new("The local launcher configuration is invalid.");
}

public sealed class LauncherException(string message) : Exception(message);

public sealed record LauncherStatus(bool BackendReady, bool SpeechReady, string Message)
{
    public bool Ready => BackendReady && SpeechReady;
}

public sealed record HostResult(int ExitCode, string Output, bool TimedOut = false);

public interface IHostExecutor
{
    Task<HostResult> ExecuteAsync(IReadOnlyList<string> arguments, TimeSpan timeout, CancellationToken cancellationToken);
}

public interface IJsonHealthClient
{
    Task<JsonDocument?> GetAsync(Uri uri, TimeSpan timeout, CancellationToken cancellationToken);
}

internal static class DockerCommands
{
    // These templates deliberately omit environment variables, command lines and logs.
    internal const string ContainerFormat = "{\"Id\":{{json .Id}},\"Project\":{{json (index .Config.Labels \"project\")}},\"Running\":{{json .State.Running}},\"State\":{{json .State.Status}},\"NetworkMode\":{{json .HostConfig.NetworkMode}},\"Mounts\":{{json .Mounts}},\"Ports\":{{json .HostConfig.PortBindings}},\"Networks\":{{json .NetworkSettings.Networks}}}";
    internal const string VolumeFormat = "{\"Name\":{{json .Name}},\"Project\":{{json (index .Labels \"project\")}}}";
    internal const string NetworkFormat = "{\"Id\":{{json .Id}},\"Name\":{{json .Name}},\"Project\":{{json (index .Labels \"project\")}}}";
    internal static bool ImmutableId(string value) => Regex.IsMatch(value, @"\A[a-f0-9]{64}\z", RegexOptions.CultureInvariant);

    internal static bool Allowed(IReadOnlyList<string> args)
    {
        if (args.Count < 6 || args[0] != "-d" || !LauncherConfig.SafeIdentifier(args[1]) ||
            args[2] != "--exec" || args[3] != "docker") return false;
        if (args.Count == 6 && args[4] == "start") return ImmutableId(args[5]);
        if (args.Count != 9 || args[5] != "inspect" || args[6] != "--format" ||
            !LauncherConfig.SafeIdentifier(args[8])) return false;
        return args[4] switch
        {
            "container" => args[7] == ContainerFormat,
            "volume" => args[7] == VolumeFormat,
            "network" => args[7] == NetworkFormat,
            _ => false
        };
    }
}

public sealed class WindowsHostExecutor : IHostExecutor
{
    public async Task<HostResult> ExecuteAsync(IReadOnlyList<string> arguments, TimeSpan timeout, CancellationToken cancellationToken)
    {
        if (!DockerCommands.Allowed(arguments) || timeout <= TimeSpan.Zero)
            throw new LauncherException("The launcher refused an unsupported local operation.");
        using var process = new Process
        {
            StartInfo = new ProcessStartInfo("wsl.exe")
            {
                UseShellExecute = false, RedirectStandardOutput = true, RedirectStandardError = true,
                CreateNoWindow = true
            }
        };
        foreach (var argument in arguments) process.StartInfo.ArgumentList.Add(argument);
        using var deadline = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        deadline.CancelAfter(timeout);
        try
        {
            if (!process.Start()) throw new LauncherException("The local WSL operation could not be started.");
            var stdout = ReadBoundedAsync(process.StandardOutput, true, deadline.Token);
            var stderr = ReadBoundedAsync(process.StandardError, false, deadline.Token);
            await process.WaitForExitAsync(deadline.Token).ConfigureAwait(false);
            var output = await stdout.ConfigureAwait(false);
            await stderr.ConfigureAwait(false);
            return new HostResult(process.ExitCode, output);
        }
        catch (OperationCanceledException)
        {
            KillOwnedProcess(process);
            cancellationToken.ThrowIfCancellationRequested();
            return new HostResult(-1, "", true);
        }
        catch (Exception exception) when (exception is System.ComponentModel.Win32Exception or InvalidOperationException or IOException or LauncherException)
        {
            KillOwnedProcess(process);
            throw new LauncherException("The local WSL operation failed. Check that WSL and Docker are available.");
        }
    }

    private static async Task<string> ReadBoundedAsync(StreamReader reader, bool retain, CancellationToken token)
    {
        var builder = new StringBuilder();
        var buffer = new char[1024];
        var size = 0;
        int read;
        while ((read = await reader.ReadAsync(buffer.AsMemory(), token).ConfigureAwait(false)) > 0)
        {
            size += read;
            if (size > 65536) throw new LauncherException("The local operation returned too much output.");
            if (retain) builder.Append(buffer, 0, read);
        }
        return builder.ToString();
    }

    private static void KillOwnedProcess(Process process)
    {
        try { if (!process.HasExited) process.Kill(true); }
        catch (Exception exception) when (exception is InvalidOperationException or System.ComponentModel.Win32Exception) { }
    }
}

public sealed class LoopbackJsonHealthClient : IJsonHealthClient
{
    private static readonly HttpClient Client = new(new HttpClientHandler
    {
        AllowAutoRedirect = false, UseCookies = false, UseProxy = false
    }) { Timeout = Timeout.InfiniteTimeSpan };

    public async Task<JsonDocument?> GetAsync(Uri uri, TimeSpan timeout, CancellationToken cancellationToken)
    {
        LauncherConfig.ValidateLoopbackUrl(uri.AbsoluteUri, false);
        using var deadline = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        deadline.CancelAfter(timeout);
        try
        {
            using var response = await Client.GetAsync(uri, HttpCompletionOption.ResponseHeadersRead, deadline.Token).ConfigureAwait(false);
            if (response.StatusCode != HttpStatusCode.OK || response.Content.Headers.ContentLength > 16384) return null;
            using var stream = await response.Content.ReadAsStreamAsync(deadline.Token).ConfigureAwait(false);
            using var bounded = new MemoryStream();
            var buffer = new byte[1024];
            int read;
            while ((read = await stream.ReadAsync(buffer, deadline.Token).ConfigureAwait(false)) > 0)
            {
                if (bounded.Length + read > 16384) return null;
                bounded.Write(buffer, 0, read);
            }
            return JsonDocument.Parse(bounded.ToArray(), new JsonDocumentOptions { MaxDepth = 8 });
        }
        catch (OperationCanceledException)
        {
            cancellationToken.ThrowIfCancellationRequested();
            return null;
        }
        catch (Exception exception) when (exception is HttpRequestException or IOException or JsonException)
        {
            return null;
        }
    }
}

public sealed class LauncherCore
{
    private readonly LauncherConfig config;
    private readonly IHostExecutor host;
    private readonly IJsonHealthClient health;
    private readonly SemaphoreSlim operation = new(1, 1);

    public LauncherCore(LauncherConfig config, IHostExecutor? host = null, IJsonHealthClient? health = null)
    {
        config.Validate();
        this.config = config;
        this.host = host ?? new WindowsHostExecutor();
        this.health = health ?? new LoopbackJsonHealthClient();
    }

    public Task<LauncherStatus> CheckAsync(CancellationToken cancellationToken) => RunAsync(false, null, cancellationToken);
    public Task<LauncherStatus> StartAsync(IProgress<string>? progress, CancellationToken cancellationToken) => RunAsync(true, progress, cancellationToken);

    private async Task<LauncherStatus> RunAsync(bool start, IProgress<string>? progress, CancellationToken cancellationToken)
    {
        await operation.WaitAsync(cancellationToken).ConfigureAwait(false);
        using var deadline = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        deadline.CancelAfter(TimeSpan.FromSeconds(start ? config.StartTimeoutSeconds : Math.Min(30, config.StartTimeoutSeconds)));
        try
        {
            progress?.Report("Checking the existing local services…");
            var topology = await InspectTopologyAsync(deadline.Token).ConfigureAwait(false);
            if (start)
            {
                foreach (var container in topology)
                {
                    if (container.Running) continue;
                    progress?.Report($"Starting {container.Role}…");
                    var result = await ExecuteAsync(["-d", config.Distro, "--exec", "docker", "start", container.Id], deadline.Token).ConfigureAwait(false);
                    if (result.ExitCode != 0 || result.TimedOut) throw new LauncherException("An existing local service could not be started.");
                }
            }
            LauncherStatus state;
            do
            {
                state = await ReadHealthAsync(deadline.Token,
                    start || (topology[0].Running && topology[1].Running), start || topology[2].Running).ConfigureAwait(false);
                if (state.Ready || !start) return state;
                progress?.Report(state.Message);
                await Task.Delay(TimeSpan.FromSeconds(1), deadline.Token).ConfigureAwait(false);
            } while (true);
        }
        catch (OperationCanceledException)
        {
            cancellationToken.ThrowIfCancellationRequested();
            return new LauncherStatus(false, false, "Local services did not become ready before the timeout. Check the local services and try again.");
        }
        catch (LauncherException exception)
        {
            return new LauncherStatus(false, false, SafeError(exception.Message));
        }
        catch (Exception)
        {
            return new LauncherStatus(false, false, "Local service information could not be verified. Check WSL and Docker, then try again.");
        }
        finally { operation.Release(); }
    }

    private async Task<HostResult> ExecuteAsync(IReadOnlyList<string> args, CancellationToken token)
    {
        if (!DockerCommands.Allowed(args)) throw new LauncherException("The launcher refused an unsupported local operation.");
        var result = await host.ExecuteAsync(args, TimeSpan.FromSeconds(10), token).WaitAsync(token).ConfigureAwait(false);
        if (result.Output.Length > 65536) throw new LauncherException("Local service information could not be verified.");
        return result;
    }

    private async Task<JsonDocument> InspectAsync(string kind, string name, string format, CancellationToken token)
    {
        var result = await ExecuteAsync(["-d", config.Distro, "--exec", "docker", kind, "inspect", "--format", format, name], token).ConfigureAwait(false);
        if (result.ExitCode != 0 || result.TimedOut) throw new LauncherException("An expected local service resource is missing or unavailable. Run the local installer first.");
        try
        {
            var doc = JsonDocument.Parse(result.Output, new JsonDocumentOptions { MaxDepth = 12 });
            if (doc.RootElement.ValueKind != JsonValueKind.Object || !UniqueProperties(doc.RootElement))
            { doc.Dispose(); throw new JsonException(); }
            return doc;
        }
        catch (JsonException) { throw new LauncherException("Local service ownership could not be verified."); }
    }

    private async Task<IReadOnlyList<ContainerInfo>> InspectTopologyAsync(CancellationToken token)
    {
        using var dbDoc = await InspectAsync("container", config.DatabaseContainer, DockerCommands.ContainerFormat, token).ConfigureAwait(false);
        using var serverDoc = await InspectAsync("container", config.ServerContainer, DockerCommands.ContainerFormat, token).ConfigureAwait(false);
        using var speechDoc = await InspectAsync("container", config.SpeechContainer, DockerCommands.ContainerFormat, token).ConfigureAwait(false);
        using var dbVolume = await InspectAsync("volume", config.DatabaseVolume, DockerCommands.VolumeFormat, token).ConfigureAwait(false);
        using var serverVolume = await InspectAsync("volume", config.ServerVolume, DockerCommands.VolumeFormat, token).ConfigureAwait(false);
        using var networkDoc = await InspectAsync("network", config.Network, DockerCommands.NetworkFormat, token).ConfigureAwait(false);
        RequireResource(dbVolume.RootElement, config.DatabaseVolume);
        RequireResource(serverVolume.RootElement, config.ServerVolume);
        RequireResource(networkDoc.RootElement, config.Network);
        var networkId = String(networkDoc.RootElement, "Id");
        if (!DockerCommands.ImmutableId(networkId)) throw OwnershipFailure();
        var db = Container(dbDoc.RootElement, "database");
        var server = Container(serverDoc.RootElement, "backend");
        var speech = Container(speechDoc.RootElement, "speech service");
        if (new[] { db.Id, server.Id, speech.Id }.Distinct(StringComparer.Ordinal).Count() != 3) throw OwnershipFailure();
        RequireVolume(dbDoc.RootElement, config.DatabaseVolume, "/var/lib/postgresql/data");
        RequireVolume(serverDoc.RootElement, config.ServerVolume, "/var/www/html/Synthserver");
        RequireNetwork(dbDoc.RootElement, networkId);
        RequireNetwork(speechDoc.RootElement, networkId);
        if (String(serverDoc.RootElement, "NetworkMode") != "container:" + db.Id) throw OwnershipFailure();
        RequirePorts(dbDoc.RootElement, "80/tcp", new Uri(config.BackendBaseUrl).Port);
        RequirePorts(serverDoc.RootElement, null, 0);
        RequirePorts(speechDoc.RootElement, "9876/tcp", config.SpeechHealthUri.Port);
        return [db, server, speech];
    }

    private ContainerInfo Container(JsonElement root, string role)
    {
        if (String(root, "Project") != config.Project) throw OwnershipFailure();
        var id = String(root, "Id");
        if (!DockerCommands.ImmutableId(id) || !root.TryGetProperty("Running", out var running) ||
            running.ValueKind is not (JsonValueKind.True or JsonValueKind.False)) throw OwnershipFailure();
        var state = String(root, "State");
        if ((running.GetBoolean() && state != "running") || (!running.GetBoolean() && state is not ("created" or "exited")))
            throw new LauncherException("A local service is paused, restarting or in an unsupported state. Check Docker first.");
        return new ContainerInfo(id, running.GetBoolean(), role);
    }

    private void RequireResource(JsonElement root, string name)
    {
        if (String(root, "Name") != name || String(root, "Project") != config.Project) throw OwnershipFailure();
    }

    private static void RequireVolume(JsonElement root, string name, string destination)
    {
        if (!root.TryGetProperty("Mounts", out var mounts) || mounts.ValueKind != JsonValueKind.Array) throw OwnershipFailure();
        var found = 0;
        foreach (var mount in mounts.EnumerateArray())
        {
            if (String(mount, "Destination") != destination) continue;
            if (String(mount, "Type") != "volume" || String(mount, "Name") != name) throw OwnershipFailure();
            found++;
        }
        if (found != 1) throw OwnershipFailure();
    }

    private void RequireNetwork(JsonElement root, string id)
    {
        if (String(root, "NetworkMode") != config.Network || !root.TryGetProperty("Networks", out var networks) ||
            networks.ValueKind != JsonValueKind.Object || !networks.TryGetProperty(config.Network, out var network) ||
            String(network, "NetworkID") != id || networks.EnumerateObject().Count() != 1) throw OwnershipFailure();
    }

    private static void RequirePorts(JsonElement root, string? requiredContainerPort, int requiredHostPort)
    {
        if (!root.TryGetProperty("Ports", out var ports) || ports.ValueKind != JsonValueKind.Object) throw OwnershipFailure();
        var found = false;
        foreach (var port in ports.EnumerateObject())
        {
            if (port.Value.ValueKind == JsonValueKind.Null) continue;
            if (port.Value.ValueKind != JsonValueKind.Array) throw OwnershipFailure();
            foreach (var binding in port.Value.EnumerateArray())
            {
                // This topology has no published database port or unrelated listener.
                if (port.Name != requiredContainerPort || String(binding, "HostIp") != "127.0.0.1" ||
                    String(binding, "HostPort") != requiredHostPort.ToString(System.Globalization.CultureInfo.InvariantCulture))
                    throw new LauncherException("Local service ports must match the configured loopback-only topology.");
                if (found) throw OwnershipFailure();
                found = true;
            }
        }
        if (requiredContainerPort is not null && !found) throw OwnershipFailure();
    }

    private async Task<LauncherStatus> ReadHealthAsync(CancellationToken token, bool backendRunning, bool speechRunning)
    {
        using var backend = await health.GetAsync(config.BackendHealthUri, TimeSpan.FromSeconds(3), token).WaitAsync(token).ConfigureAwait(false);
        using var speech = await health.GetAsync(config.SpeechHealthUri, TimeSpan.FromSeconds(3), token).WaitAsync(token).ConfigureAwait(false);
        var backendReady = backendRunning && backend is not null && BackendReady(backend.RootElement);
        var speechReady = speechRunning && speech is not null && SpeechReady(speech.RootElement);
        var message = (backendReady, speechReady) switch
        {
            (true, true) => "SYNTH backend and speech service are ready.",
            (true, false) => "The backend is ready; waiting for the speech service.",
            (false, true) => "The speech service is ready; waiting for the backend.",
            _ => "Waiting for the backend and speech service."
        };
        return new LauncherStatus(backendReady, speechReady, message);
    }

    private static bool BackendReady(JsonElement root) => UniqueObject(root) &&
        IsString(root, "service", "SYNTH Server") && IsString(root, "status", "ok") &&
        IsBoolean(root, "database", true) && IsBoolean(root, "schema", true) && IsBoolean(root, "background_processor", true);
    private static bool SpeechReady(JsonElement root) => UniqueObject(root) &&
        IsString(root, "service", "SYNTH Whisper") && IsString(root, "status", "ready") &&
        IsBoolean(root, "microphone_capture", false);
    private static bool UniqueObject(JsonElement root) => root.ValueKind == JsonValueKind.Object &&
        root.EnumerateObject().Select(p => p.Name).Distinct(StringComparer.Ordinal).Count() == root.EnumerateObject().Count();
    private static bool UniqueProperties(JsonElement value) => value.ValueKind switch
    {
        JsonValueKind.Object => UniqueObject(value) && value.EnumerateObject().All(p => UniqueProperties(p.Value)),
        JsonValueKind.Array => value.EnumerateArray().All(UniqueProperties),
        _ => true
    };
    private static bool IsString(JsonElement root, string key, string value) => root.TryGetProperty(key, out var field) &&
        field.ValueKind == JsonValueKind.String && field.GetString() == value;
    private static bool IsBoolean(JsonElement root, string key, bool value) => root.TryGetProperty(key, out var field) &&
        field.ValueKind == (value ? JsonValueKind.True : JsonValueKind.False);
    private static string String(JsonElement root, string name) => root.TryGetProperty(name, out var field) &&
        field.ValueKind == JsonValueKind.String ? field.GetString()! : throw OwnershipFailure();
    private static LauncherException OwnershipFailure() => new("Local service ownership or saved-data mounts do not match the configured installation. No services were started.");
    private static string SafeError(string message) => message switch
    {
        "The launcher refused an unsupported local operation." or
        "The local WSL operation failed. Check that WSL and Docker are available." or
        "An expected local service resource is missing or unavailable. Run the local installer first." or
        "Local service ownership could not be verified." or
        "Local service information could not be verified." or
        "Local service ownership or saved-data mounts do not match the configured installation. No services were started." or
        "A local service is paused, restarting or in an unsupported state. Check Docker first." or
        "Local service ports must match the configured loopback-only topology." or
        "An existing local service could not be started." => message,
        _ => "Local service information could not be verified. Check WSL and Docker, then try again."
    };
    private sealed record ContainerInfo(string Id, bool Running, string Role);
}
