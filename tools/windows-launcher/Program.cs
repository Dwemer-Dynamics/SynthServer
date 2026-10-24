// SPDX-License-Identifier: GPL-3.0-only
using System.Text.Json;
using Synth.Launcher;

internal static class Program
{
    [STAThread]
    private static int Main(string[] args)
    {
        try
        {
            var configPath = Path.Combine(AppContext.BaseDirectory, "SYNTH.launcher.json");
            string? mode = null;
            string? previewPath = null;
            for (var i = 0; i < args.Length; i++)
            {
                switch (args[i])
                {
                    case "--config" when i + 1 < args.Length:
                        configPath = Path.GetFullPath(args[++i]);
                        break;
                    case "--check" when mode is null:
                        mode = "check";
                        break;
                    case "--preview" when mode is null && i + 1 < args.Length:
                        mode = "preview";
                        previewPath = Path.GetFullPath(args[++i]);
                        break;
                    default:
                        throw new LauncherException("Use SYNTH.exe, or --check with an optional --config path.");
                }
            }
            var config = LauncherConfig.Load(configPath);
            if (mode == "check")
            {
                using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(30));
                var status = new LauncherCore(config).CheckAsync(timeout.Token).GetAwaiter().GetResult();
                Console.WriteLine(JsonSerializer.Serialize(status));
                return status.Ready ? 0 : 2;
            }
            ApplicationConfiguration.Initialize();
            using var form = new LauncherForm(config);
            if (mode == "preview")
            {
                form.RenderPreview(previewPath!);
                return 0;
            }
            Application.Run(form);
            return 0;
        }
        catch (Exception exception)
        {
            var message = exception is LauncherException ? exception.Message :
                exception is OperationCanceledException ? "The local readiness check timed out." :
                "SYNTH could not open. Check the local launcher configuration and Windows prerequisites.";
            if (args.Contains("--check"))
                Console.WriteLine(JsonSerializer.Serialize(new { Ready = false, Message = message }));
            else
                MessageBox.Show(message, "SYNTH", MessageBoxButtons.OK, MessageBoxIcon.Information);
            return 1;
        }
    }
}
