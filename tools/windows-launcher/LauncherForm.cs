// SPDX-License-Identifier: GPL-3.0-only
using System.Diagnostics;
using Synth.Launcher;

internal sealed class LauncherForm : Form
{
    private readonly LauncherConfig config;
    private readonly LauncherCore core;
    private readonly Label headline = new();
    private readonly Label message = new();
    private readonly Label backend = new();
    private readonly Label speech = new();
    private readonly Button start = new();
    private readonly Button dashboard = new();
    private readonly Button game = new();
    private readonly Button cancel = new();
    private readonly CheckBox automaticDashboard = new();
    private CancellationTokenSource? operation;
    private bool dashboardOpened;
    private bool closing;
    private bool preview;
    private LauncherStatus? lastStatus;
    private static readonly Color Green = Color.FromArgb(144, 226, 175);
    private static readonly Color Muted = Color.FromArgb(164, 176, 168);
    protected override bool ShowWithoutActivation => preview;

    public LauncherForm(LauncherConfig config)
    {
        this.config = config;
        core = new LauncherCore(config);
        Text = "SYNTH";
        BackColor = Color.FromArgb(18, 24, 21);
        ForeColor = Color.FromArgb(234, 241, 235);
        Font = new Font("Segoe UI", 10);
        ClientSize = new Size(620, 470);
        MinimumSize = new Size(620, 500);
        StartPosition = FormStartPosition.CenterScreen;
        AutoScaleMode = AutoScaleMode.Dpi;

        var layout = new TableLayoutPanel
        {
            Dock = DockStyle.Fill, Padding = new Padding(28), ColumnCount = 1, RowCount = 8
        };
        foreach (var height in new[] { 50f, 25f, 58f, 64f, 64f, 37f, 48f, 38f })
            layout.RowStyles.Add(new RowStyle(SizeType.Absolute, height));
        var title = new Label
        {
            Text = "SYNTH", Font = new Font("Segoe UI", 26, FontStyle.Bold),
            ForeColor = Green, Dock = DockStyle.Fill, AutoSize = false
        };
        layout.Controls.Add(title);
        layout.Controls.Add(new Label { Text = "Your local backend and dashboard", Dock = DockStyle.Fill, ForeColor = Muted });
        headline.Text = "Preparing your local setup";
        headline.Font = new Font("Segoe UI", 15, FontStyle.Bold);
        headline.Dock = DockStyle.Fill;
        headline.TextAlign = ContentAlignment.MiddleLeft;
        layout.Controls.Add(headline);
        layout.Controls.Add(Card(backend, "BACKEND", "Database, schema and background workers"));
        layout.Controls.Add(Card(speech, "SPEECH", "Local Whisper transcription service"));
        automaticDashboard.Text = "Open dashboard when ready";
        automaticDashboard.Checked = true;
        automaticDashboard.Dock = DockStyle.Fill;
        automaticDashboard.AccessibleName = automaticDashboard.Text;
        layout.Controls.Add(automaticDashboard);

        var buttons = new FlowLayoutPanel { Dock = DockStyle.Fill, WrapContents = false };
        SetupButton(start, "Start services", 132);
        SetupButton(dashboard, "Open dashboard", 145);
        SetupButton(game, "Launch Fallout 4", 145);
        dashboard.Enabled = game.Enabled = false;
        buttons.Controls.AddRange(new Control[] { start, dashboard, game });
        layout.Controls.Add(buttons);
        var footer = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 2 };
        footer.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        footer.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 78));
        message.Dock = DockStyle.Fill;
        message.ForeColor = Muted;
        message.AutoEllipsis = true;
        message.AccessibleName = "Launcher status";
        cancel.Text = "Cancel";
        cancel.Dock = DockStyle.Fill;
        cancel.Enabled = false;
        footer.Controls.Add(message, 0, 0);
        footer.Controls.Add(cancel, 1, 0);
        layout.Controls.Add(footer);
        Controls.Add(layout);

        start.Click += async (_, _) => await StartServicesAsync();
        dashboard.Click += (_, _) => OpenDashboard();
        game.Click += async (_, _) => await LaunchGameAsync();
        cancel.Click += (_, _) => operation?.Cancel();
        Shown += async (_, _) => { if (!preview) await StartServicesAsync(); };
        FormClosing += (_, _) => { closing = true; operation?.Cancel(); };
    }

    private static Panel Card(Label value, string name, string detail)
    {
        var card = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 2, RowCount = 1, BackColor = Color.FromArgb(29, 39, 33), Margin = new Padding(0, 0, 0, 9) };
        card.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        card.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 115));
        card.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        var descriptionPanel = new Panel { Dock = DockStyle.Fill, Margin = Padding.Empty };
        var label = new Label { Text = name, ForeColor = Muted, Font = new Font("Segoe UI", 9, FontStyle.Bold), Location = new Point(14, 10), AutoSize = true };
        var description = new Label { Text = detail, ForeColor = Muted, Location = new Point(14, 29), AutoSize = true };
        value.Text = "Waiting";
        value.ForeColor = Muted;
        value.Dock = DockStyle.Fill;
        value.Margin = Padding.Empty;
        value.TextAlign = ContentAlignment.MiddleRight;
        value.Padding = new Padding(0, 0, 14, 0);
        descriptionPanel.Controls.AddRange(new Control[] { label, description });
        card.Controls.Add(descriptionPanel, 0, 0);
        card.Controls.Add(value, 1, 0);
        return card;
    }

    private static void SetupButton(Button button, string text, int width)
    {
        button.Text = text;
        button.AccessibleName = text;
        button.Size = new Size(width, 35);
        button.Margin = new Padding(0, 0, 10, 0);
        button.FlatStyle = FlatStyle.Flat;
        button.BackColor = Color.FromArgb(42, 65, 50);
        button.ForeColor = Color.FromArgb(234, 241, 235);
    }

    private void Busy(bool value)
    {
        start.Enabled = !value;
        cancel.Enabled = value;
        dashboard.Enabled = !value && lastStatus?.BackendReady == true;
        game.Enabled = !value && lastStatus?.Ready == true && ValidGamePath();
    }

    private void ApplyStatus(LauncherStatus status)
    {
        lastStatus = status;
        headline.Text = status.Ready ? "Your local setup is ready" : "Setup needs attention";
        backend.Text = status.BackendReady ? "Ready" : "Unavailable";
        backend.ForeColor = status.BackendReady ? Green : Color.FromArgb(239, 196, 133);
        speech.Text = status.SpeechReady ? "Ready" : "Unavailable";
        speech.ForeColor = status.SpeechReady ? Green : Color.FromArgb(239, 196, 133);
        message.Text = status.Message;
    }

    private async Task StartServicesAsync()
    {
        if (operation is not null) return;
        operation = new CancellationTokenSource();
        Busy(true);
        headline.Text = "Starting your local setup";
        try
        {
            var progress = new Progress<string>(text => { if (!closing) message.Text = text; });
            var status = await core.StartAsync(progress, operation.Token);
            if (closing) return;
            operation.Token.ThrowIfCancellationRequested();
            ApplyStatus(status);
            if (status.Ready && automaticDashboard.Checked && !dashboardOpened) OpenDashboard();
        }
        catch (Exception exception)
        {
            if (closing) return;
            lastStatus = null;
            headline.Text = exception is OperationCanceledException ? "Startup cancelled" : "Could not start SYNTH";
            message.Text = SafeError(exception);
            backend.Text = speech.Text = "Check needed";
        }
        finally
        {
            operation?.Dispose();
            operation = null;
            if (!closing) Busy(false);
        }
    }

    private void OpenDashboard()
    {
        if (preview || lastStatus?.BackendReady != true) return;
        try
        {
            Process.Start(new ProcessStartInfo(config.DashboardUri.AbsoluteUri) { UseShellExecute = true });
            dashboardOpened = true;
        }
        catch
        {
            message.Text = "Windows could not open your browser. Open the local dashboard manually.";
        }
    }

    private bool ValidGamePath() => config.GameExecutable is { } path &&
        Path.IsPathFullyQualified(path) && !path.StartsWith(@"\\", StringComparison.Ordinal) &&
        string.Equals(Path.GetFileName(path), "f4se_loader.exe", StringComparison.OrdinalIgnoreCase) && File.Exists(path);

    private async Task LaunchGameAsync()
    {
        if (operation is not null || !ValidGamePath()) return;
        operation = new CancellationTokenSource(TimeSpan.FromSeconds(30));
        Busy(true);
        try
        {
            var status = await core.CheckAsync(operation.Token);
            if (closing) return;
            operation.Token.ThrowIfCancellationRequested();
            ApplyStatus(status);
            if (!status.Ready) return;
            if (Process.GetProcessesByName("Fallout4").Length != 0 || Process.GetProcessesByName("f4se_loader").Length != 0)
            {
                message.Text = "Fallout 4 is already running.";
                return;
            }
            var path = config.GameExecutable!;
            Process.Start(new ProcessStartInfo(path)
            {
                WorkingDirectory = Path.GetDirectoryName(path)!, UseShellExecute = true
            });
            message.Text = "Fallout 4 launch requested through F4SE.";
        }
        catch (Exception exception)
        {
            if (!closing) message.Text = SafeError(exception);
        }
        finally
        {
            operation?.Dispose();
            operation = null;
            if (!closing) Busy(false);
        }
    }

    private static string SafeError(Exception exception) => exception is LauncherException ? exception.Message :
        exception is OperationCanceledException ? "The operation ended before readiness was confirmed." :
        "Check WSL, Docker and the local launcher configuration, then try again.";

    public void RenderPreview(string path)
    {
        preview = true;
        ShowInTaskbar = false;
        StartPosition = FormStartPosition.Manual;
        Location = new Point(-32000, -32000);
        Opacity = 0;
        ApplyStatus(new LauncherStatus(true, true, "Ready for your next SYNTH session."));
        Busy(false);
        Show();
        PerformLayout();
        Application.DoEvents();
        using var image = new Bitmap(Width, Height);
        DrawToBitmap(image, new Rectangle(0, 0, Width, Height));
        image.Save(path, System.Drawing.Imaging.ImageFormat.Png);
        Close();
    }
}
