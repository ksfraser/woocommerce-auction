# GitHub Actions Local (Self-Hosted) Runner Setup

This guide explains how to set up a self-hosted GitHub Actions runner on your local machine or server. This allows you to run CI/CD workflows for **free**, without using GitHub's 2,000 minute/month quota.

---

## Why Use a Local Runner?

| Feature | GitHub-Hosted | Self-Hosted |
|---------|---------------|------------|
| **Cost** | 2,000 min/month free, then $0.25/1,000 min | ✅ FREE |
| **Performance** | Slower (cloud provisioning) | ✅ Faster (local machine) |
| **Customization** | Limited | ✅ Full control |
| **Persistence** | None (ephemeral) | ✅ Can maintain state |
| **Availability** | 99.9% SLA | Your machine schedule |

---

## Prerequisites

### System Requirements
- **CPU**: 2+ cores
- **RAM**: 4GB minimum (8GB recommended)
- **Disk**: 10GB+ free space
- **OS**: Windows, macOS, or Linux
- **Network**: Stable internet connection, outbound HTTPS (443) allowed

### Software Requirements
- Git
- Docker (recommended for isolated environments)
- GitHub account with repository access
- Admin access to your machine

---

## Part 1: Configure Runner in GitHub Repository

### Step 1: Navigate to Repository Settings
1. Go to your GitHub repository
2. Click **Settings** → **Actions** → **Runners**
3. Click **New self-hosted runner** button

### Step 2: Select Runner Configuration
- **Runner image**: Choose based on your OS
  - Windows (local development)
  - Linux (Ubuntu/Debian)
  - macOS
- **Architecture**: x64 (standard), ARM64 (if supported)

GitHub will provide you with **download** and **configuration** commands. Save these for Step 3.

---

## Part 2: Install and Configure Local Runner

### For Windows (Local Machine)

**Step 1: Create runner directory**
```powershell
mkdir C:\GitHub\actions-runner
cd C:\GitHub\actions-runner
```

**Step 2: Download latest runner release**
```powershell
# Get the latest version
$latestRelease = "https://api.github.com/repos/actions/runner/releases/latest"
$info = $latestRelease | ConvertFrom-Json
$downloadUrl = $info.assets | Where-Object { $_.name -like "*win-x64.zip" } | Select -ExpandProperty browser_download_url

# Download
Invoke-WebRequest -Uri $downloadUrl -OutFile actions-runner-win-x64.zip

# Extract
Expand-Archive -Path actions-runner-win-x64.zip -DestinationPath .
```

### Step 2: Configure runner** (use GitHub's provided token)
```powershell
./config.cmd --url https://github.com/ksfraser/woocommerce-auction `
  --token REPLACE_WITH_GITHUB_PROVIDED_TOKEN
```

**Step 4: Run the runner**

**Option A: Run as service (recommended)**
```powershell
# Install as Windows Service (runs on startup)
./svc.cmd install

# Start the service
./svc.cmd start

# View status
Get-Service -Name GitHub\ Actions\ Runner | Select Status
```

**Option B: Run in foreground (testing)**
```powershell
./run.cmd
```

### For macOS / Linux

**Step 1: Create runner directory**
```bash
mkdir -p ~/actions-runner && cd ~/actions-runner
```

**Step 2: Download latest runner**
```bash
# Detect OS
if [[ "$OSTYPE" == "linux-gnu"* ]]; then
    RUNNER_OS="linux"
    ARCH="x64"
elif [[ "$OSTYPE" == "darwin"* ]]; then
    RUNNER_OS="osx"
    ARCH="x64"
fi

# Download latest
curl -o actions-runner-$RUNNER_OS-$ARCH.tar.gz \
  -L https://github.com/actions/runner/releases/download/v2.318.0/actions-runner-$RUNNER_OS-$ARCH-2.318.0.tar.gz

# Extract
tar xzf actions-runner-$RUNNER_OS-$ARCH.tar.gz
```

**Step 3: Configure runner** (use GitHub's provided token)
```bash
./config.sh --url https://github.com/ksfraser/woocommerce-auction \
  --token REPLACE_WITH_GITHUB_PROVIDED_TOKEN
```

**Step 4: Run as service**

**macOS (Launchd)**
```bash
# Install
sudo ./svc.sh install

# Start
sudo ./svc.sh start

# Status
sudo ./svc.sh status
```

**Linux (systemd)**
```bash
# Install
sudo ./svc.sh install

# Start
sudo systemctl start actions-runner

# Status
sudo systemctl status actions-runner

# Enable on boot
sudo systemctl enable actions-runner
```

---

## Part 3: Verify Runner is Connected

### Check GitHub Repository Settings
1. Go to **Settings** → **Actions** → **Runners**
2. Look for your runner with status **Idle** (green circle)
3. If it shows **Offline**, restart the runner and check network connection

### Test with a Workflow
Push a commit to `main` or create a PR to trigger the workflow. It will now run on your local runner.

### View Runner Logs
- **Windows Service**: `Event Viewer` → `Windows Logs` → `Application`
- **macOS/Linux**: `~/actions-runner/_diag/` directory

---

## Part 4: Configure Runner Labels (Optional)

Labels allow workflows to specifically target your local runner.

### Add Custom Labels During Setup
```powershell
./config.cmd --url https://github.com/YOUR-USERNAME/YOUR-REPO `
  --token TOKEN `
  --labels "self-hosted,local,windows"
```

### Configure Workflow to Use Local Runner
```yaml
jobs:
  test:
    runs-on: [self-hosted, local]  # Uses your local runner
    steps:
      - uses: actions/checkout@v3
      - run: echo "Running on local runner!"
```

---

## Troubleshooting

### Runner Not Connecting

**Problem**: Runner shows "Offline" in GitHub
```powershell
# Check runner status
cd C:\GitHub\actions-runner
./svc.cmd status

# View logs
Get-Content _diag\Runner_*.log
```

**Solution**: 
- Verify internet connection
- Check GitHub token hasn't expired (re-run config)
- Verify firewall allows outbound HTTPS (443)
- Restart the service

### Port Already in Use
```powershell
# Find what's using port 443
netstat -ano | findstr :443

# Kill the process
taskkill /PID <PID> /F
```

### Runner Crashes During Workflow
```powershell
# Check system resources
Get-Process GitHub* | Select ProcessName, @{N="Memory(MB)"; E={[math]::Round($_.WS/1MB)}}

# Increase Docker memory if using containers
# Docker Desktop → Settings → Resources → Memory: 8GB (recommended)
```

### "Access Denied" Errors on Windows
```powershell
# Run PowerShell as Administrator
Start-Process powershell -Verb runAs

# Try again
./config.cmd --url ... --token ...
```

---

## Maintenance

### Keep Runner Updated
Self-hosted runners auto-update when new versions are released. To manually update:

```powershell
# Stop the service
./svc.cmd stop

# Remove old runner
rm -r ~/actions-runner

# Install new version (repeat Part 2)
```

### Monitor Runner Health

**Create a status check script** (run weekly):
```powershell
# Check-RunnerHealth.ps1
cd C:\GitHub\actions-runner

$status = & ./svc.cmd status
$logs = Get-Content _diag\Runner_*.log -Tail 20

if ($status -like "*running*") {
    Write-Host "✅ Runner is healthy"
} else {
    Write-Host "❌ Runner not running"
    ./svc.cmd start
}
```

### Backup Configuration
```powershell
# Backup runner config
Copy-Item .runner C:\Backups\runner-backup.json -Force
Copy-Item .credentials_rsaparams C:\Backups\credentials-backup.json -Force
```

---

## Security Considerations

⚠️ **Important**: Self-hosted runners have security implications:

### Best Practices
1. **Restrict access**: Only run on trusted networks
2. **Firewall rules**: Limit outbound connections to GitHub API
3. **Rotate tokens**: Re-register runner monthly
4. **Monitor logs**: Check for suspicious activity
5. **Update regularly**: Keep runner software current
6. **Network isolation**: Consider running in a VM or container

### For Production
- Use dedicated hardware or VM
- Implement network monitoring
- Rotate GitHub tokens quarterly
- Log all workflow executions
- Use organization-level runners (multiple admins)

---

## Windows Service Management

### Service Commands
```powershell
# Install as service
cd C:\GitHub\actions-runner
./svc.cmd install

# Start service
./svc.cmd start

# Stop service
./svc.cmd stop

# Uninstall service
./svc.cmd uninstall

# Check status
Get-Service -Name "GitHub Actions Runner*" | Select Status, StartType

# View logs
wevtutil qe Application /q:"*GitHub*" /f:text
```

### Auto-Start on Boot (Windows)
The service is automatically set to start on boot after `./svc.cmd install`.

To verify:
```powershell
Get-Service -Name "GitHub Actions Runner*" | Select StartType
# Should show "Automatic"
```

---

## macOS/Linux Service Management

### systemd Commands (Linux)
```bash
# View service status
sudo systemctl status actions-runner

# Restart service
sudo systemctl restart actions-runner

# View recent logs
sudo journalctl -u actions-runner -n 50

# Enable on boot
sudo systemctl enable actions-runner

# Disable on boot
sudo systemctl disable actions-runner
```

### Launchd Commands (macOS)
```bash
# Load launch agent
launchctl load ~/Library/LaunchAgents/actions.runner.plist

# Unload launch agent
launchctl unload ~/Library/LaunchAgents/actions.runner.plist

# View status
launchctl list | grep actions.runner

# View logs
log stream --predicate 'process == "actions-runner"'
```

---

## Using Local Runner with Docker

For maximum isolation, run the GitHub Actions runner inside a Docker container:

```dockerfile
FROM ubuntu:22.04

RUN apt-get update && apt-get install -y \
    curl \
    git \
    wget \
    docker.io \
    php8.1-cli \
    composer

WORKDIR /opt/actions-runner

# Download and setup runner
RUN curl -o actions-runner-linux-x64.tar.gz \
    -L https://github.com/actions/runner/releases/download/v2.318.0/actions-runner-linux-x64-2.318.0.tar.gz && \
    tar xzf actions-runner-linux-x64.tar.gz

COPY docker-entrypoint.sh .
RUN chmod +x docker-entrypoint.sh

ENTRYPOINT ["./docker-entrypoint.sh"]
```

Then run:
```bash
docker build -t github-runner .
docker run -d \
  -e GITHUB_TOKEN=<token> \
  -e GITHUB_REPO=yourusername/yith-auctions-for-woocommerce \
  -v /var/run/docker.sock:/var/run/docker.sock \
  github-runner
```

---

## Monitoring and Alerts

### GitHub Actions Logs
- Access via: **Settings** → **Actions** → **Runners**
- Click runner name to see last 30 jobs and logs

### CloudWatch Integration (AWS)
If running on EC2, send logs to CloudWatch:
```powershell
# Install CloudWatch agent
# Configure to monitorboth runner service and workflow logs
```

### Email Alerts on Failure
GitHub can notify you of workflow failures. Set up in:
**Settings** → **Notifications** → **Email notifications**

---

## Cost Savings Summary

| Scenario | GitHub-Hosted Cost | Local Runner Cost |
|----------|-------------------|------------------|
| 10 commits/month | FREE | ✅ FREE |
| 100 commits/month | FREE | ✅ FREE |
| 1,000 commits/month | ~$400/month | ✅ FREE |
| 10,000 commits/month | ~$4,000/month | ✅ FREE (just electricity) |

**With a local runner: You pay only for electricity (~$10-20/month for a typical PC running 24/7)**

---

## Next Steps

1. ✅ Follow Part 1-4 to install your local runner
2. ✅ Push a commit to `main` to trigger a test workflow
3. ✅ Monitor your runner in GitHub repository settings
4. ✅ Set up service auto-start for persistence
5. ✅ Review logs if any issues occur

---

## Support & Resources

- **GitHub Actions Docs**: https://docs.github.com/en/actions/hosting-your-own-runners
- **Runner Releases**: https://github.com/actions/runner/releases
- **Troubleshooting**: https://docs.github.com/en/actions/hosting-your-own-runners/managing-self-hosted-runners/troubleshooting-self-hosted-runners
- **Security**: https://docs.github.com/en/actions/hosting-your-own-runners/security-hardening-your-self-hosted-runners

---

## Quick Reference

```powershell
# Windows - Full Setup (copy/paste)
$runnerDir = "C:\GitHub\actions-runner"
mkdir $runnerDir -ErrorAction SilentlyContinue
cd $runnerDir

# Download runner v2.318.0
Invoke-WebRequest -Uri "https://github.com/actions/runner/releases/download/v2.318.0/actions-runner-win-x64-2.318.0.zip" `
  -OutFile "actions-runner-win-x64.zip"
Expand-Archive -Path "actions-runner-win-x64.zip"

# Configure (replace TOKEN - get from GitHub Settings > Actions > Runners)
cd $runnerDir
./config.cmd --url https://github.com/ksfraser/woocommerce-auction --token YOUR-TOKEN-HERE

# Install as service
./svc.cmd install

# Start service
./svc.cmd start

# Verify
Get-Service -Name "GitHub*"
```

```bash
# Linux - Full Setup (copy/paste)
mkdir -p ~/actions-runner && cd ~/actions-runner

# Download runner v2.318.0
wget https://github.com/actions/runner/releases/download/v2.318.0/actions-runner-linux-x64-2.318.0.tar.gz
tar xzf actions-runner-linux-x64-2.318.0.tar.gz

# Configure (replace TOKEN - get from GitHub Settings > Actions > Runners)
./config.sh --url https://github.com/ksfraser/woocommerce-auction --token YOUR-TOKEN-HERE

# Install as systemd service
sudo ./svc.sh install

# Start
sudo systemctl start actions-runner

# Enable on boot
sudo systemctl enable actions-runner

# Verify
sudo systemctl status actions-runner
```

---

**Last Updated**: March 2026  
**GitHub Actions Runner Version**: v2.318.0+  
**Tested On**: Windows 10/11, macOS 12+, Ubuntu 20.04+
