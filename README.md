# MySQL Reverse WebSocket Tunnel on Azure

This repository provides a one-click deployment for a **TCP-over-WebSocket relay on Azure Container Apps (ACA)** with **scale-to-zero** support. It exposes a standard MySQL port (`3306`) accessible from any external PHP `PDO` client by encapsulating traffic over an outbound WebSocket tunnel initiated on demand by your shared hosting server (e.g., OVH).

---

## 1. One-Click Azure Deployment

Click the button below to deploy the Log Analytics Workspace, the Workload Profiles Environment, and the Azure Container App (`Microsoft.App` API `2026-01-01`):

[![Deploy to Azure](https://aka.ms/deploytoazurebutton)](https://portal.azure.com/#create/Microsoft.Template/uri/https%3A%2F%2Fraw.githubusercontent.com%2Fclysss%2FwsTunnelAzure%2Fmain%2Fazuredeploy.json)

### Deployment Parameters

Define the following values during deployment (keep `WAKE_KEY` and `TUNNEL_SECRET` handy for Step 2):

| Parameter | Description | Example |
| :--- | :--- | :--- |
| **App Name** | Unique name for the Azure Container App | `mysql-tunnel-relay` |
| **Location** | Azure deployment region (defaults to Resource Group location) | `francecentral` |
| **Wake Endpoint URL** | Full HTTPS URL where you will upload `mysql_tunnel.php` on OVH (without `?key=`) | `https://yourdomain.com/secure-bridge/mysql_tunnel.php` |
| **WAKE_KEY** | Secret key appended as `?key=...` when Azure wakes up OVH | `your-wake-secret-key` |
| **TUNNEL_SECRET** | Shared secret key validating the WebSocket connection | `your-long-tunnel-secret-key` |

Once the deployment completes, open **Deployment > Outputs** in the Azure Portal and copy the **`fqdn`** value (e.g., `mysql-tunnel-relay.xxxx.francecentral.azurecontainerapps.io`).

---

## 2. Server Setup (OVH / Shared Hosting)

1. **Upload the file:** Place `mysql_tunnel.php` in the exact subfolder on your OVH web space matching the **Wake Endpoint URL** you entered in Step 1 (e.g., `/www/secure-bridge/mysql_tunnel.php`).
2. **Check PHP version:** Ensure your `.ovhconfig` runs **PHP 8.1, 8.2, or 8.3** (PHP 7.x is not supported).
3. **Define the configuration parameters** at the top of `mysql_tunnel.php`:

| Parameter | What to define | Example |
| :--- | :--- | :--- |
| **`$WAKE_KEY`** | Must match the `WAKE_KEY` parameter defined in Step 1 | `'your-wake-secret-key'` |
| **`$TUNNEL_SECRET`** | Must match the `TUNNEL_SECRET` parameter defined in Step 1 | `'your-long-tunnel-secret-key'` |
| **`$AZURE_HOST`** | The `fqdn` output copied from Azure at the end of Step 1 (no `https://`, no port) | `'mysql-tunnel-relay.xxxx.francecentral.azurecontainerapps.io'` |
| **`$AZURE_PORT`** | Multiplexed relay port on Azure (must be **`3306`**) | `3306` |
| **`$DB_HOST`** | Internal OVH MySQL server hostname | `'yourdb.mysql.db'` |
| **`$DB_PORT`** | Internal OVH MySQL port | `3306` |
| **`$DB_NAME`** | Target OVH MySQL database name | `'your_db_name'` |

---

## 3. Architecture

A single multiplexed TCP ingress on port `3306` handles both incoming `PDO` MySQL clients and outbound WebSocket control/data sessions from your OVH server. When idle for 5 minutes (`cooldownPeriod: 300`), the Azure Container App automatically scales down to `0` replicas.

```text
[PHP Client (PDO)] ──(TCP :3306)──> [Azure Container Apps :3306] <══(WS / TCP :3306)══ [OVH PHP Relay] ──(TCP :3306)──> [OVH MySQL]
                                            │
                                            └──(HTTPS Wake-on-Demand)──> [[https://yourdomain.com/.../mysql_tunnel.php?key=](https://yourdomain.com/.../mysql_tunnel.php?key=)...]
```

---

## 4. Testing & Troubleshooting

### 1. Test the OVH Wake Endpoint

Verify that `mysql_tunnel.php` responds properly to the wake trigger:

```bash
curl -i "[https://yourdomain.com/secure-bridge/mysql_tunnel.php?key=YOUR_WAKE_KEY](https://yourdomain.com/secure-bridge/mysql_tunnel.php?key=YOUR_WAKE_KEY)"
```

* **Expected output:** `HTTP/1.1 200 OK` with body `OK` (calling it without a valid key returns `403 Forbidden`).

### 2. Test the Azure Relay Port (`3306`)

From your client machine, verify that port `3306` is reachable on your Azure Container App FQDN (probing `3306` automatically wakes the container if it is scaled to `0`):

```bash
nc -zv mysql-tunnel-relay.xxxx.francecentral.azurecontainerapps.io 3306
```

* **Expected output:** `Connection to ... 3306 port [tcp/mysql] succeeded!`

### 3. Test the End-to-End PDO Connection

Run a one-line PHP check to trigger a cold wake-up and query your OVH database:

```bash
php -r '$pdo = new PDO(
    "mysql:host=mysql-tunnel-relay.xxxx.francecentral.azurecontainerapps.io;port=3306;dbname=YOUR_DB_NAME;charset=utf8mb4",
    "YOUR_OVH_DB_USER",
    "YOUR_OVH_DB_PASSWORD",
    [PDO::ATTR_TIMEOUT => 15]
);
echo "Connected! MySQL version: " . $pdo->query("SELECT VERSION()")->fetchColumn() . PHP_EOL;'
```

* **Expected output:** After a ~1–3s cold-start delay (if scaled to `0`) or ~0.5s (if warm), prints `Connected! MySQL version: 8.0.x`. Subsequent queries execute immediately while the tunnel remains active.

### Common Issues

| Symptom | Cause & Resolution |
| :--- | :--- |
| **Connection hangs or times out** (`SQLSTATE[HY000] [2002]`) | Verify that `$AZURE_HOST` in `mysql_tunnel.php` matches the exact `fqdn` output from Azure (no `https://`, no `:3306`), that `$AZURE_PORT` is set to **`3306`**, and that `$TUNNEL_SECRET` matches on both sides. |
| **Connection immediately closed** | Inspect live container logs in Azure (`az containerapp logs show -g <resource-group> -n mysql-tunnel-relay --follow`) and verify that `Wake Endpoint URL` uses `https://` and matches your OVH script path. |
| **OVH `500 Internal Server Error` on wake** | Check `.ovhconfig` and set `app.engine.version=8.2` (or `8.3`). |
| **`MaxNumberOfRegionalEnvironmentsInSubExceeded`** | Free/Student Azure subscriptions allow only 1 Container Apps Environment per region. Wait until the previous environment deletion completes before redeploying in the same region. |

---

## 5. Client Project Integration Sheet

Copy and paste the block below into the `README.md` (or `SETUP_DB.md`) of any consumer project using this MySQL tunnel, then fill in the `<...>` placeholders.

~~~markdown
## Database Configuration (Azure / OVH MySQL Tunnel)

This project connects to the OVH-hosted MySQL database through the [`clysss/wsTunnelAzure`](https://github.com/clysss/wsTunnelAzure) TCP-over-WebSocket relay deployed on **Azure Container Apps** (scale-to-zero enabled).

### 1. Relay Setup (if not already deployed)
1. **Azure Side:**
   - Deploy `azuredeploy-aca.json` in `francecentral` using the *Deploy to Azure* button on [clysss/wsTunnelAzure](https://github.com/clysss/wsTunnelAzure) with:
     - **App Name:** `<mysql-tunnel-relay>`
     - **Wake Endpoint URL:** `<https://yourdomain.com/secure-bridge/mysql_tunnel.php>`
     - **WAKE_KEY:** `<YOUR_WAKE_KEY>`
     - **TUNNEL_SECRET:** `<YOUR_TUNNEL_SECRET>`
   - Copy the `fqdn` value from **Deployment > Outputs** in the Azure Portal.
2. **OVH Side:**
   - Upload `mysql_tunnel.php` (from [github.com/clysss/wsTunnelAzure](https://github.com/clysss/wsTunnelAzure)) into a non-obvious web subfolder (e.g., `/www/secure-bridge/mysql_tunnel.php`) with PHP 8.2+ enabled in `.ovhconfig`.
   - Set `$WAKE_KEY`, `$TUNNEL_SECRET`, `$AZURE_HOST` (the Azure `fqdn`), `$AZURE_PORT = 3306`, and your OVH `$DB_HOST` / `$DB_NAME` credentials inside `mysql_tunnel.php`.

### 2. PHP PDO Connection Example

Use the following configuration in your project to connect via `PDO` (fill in the `<...>` placeholders):

```php
<?php

$dbHost = '<mysql-tunnel-relay.xxxx.francecentral.azurecontainerapps.io>';
$dbPort = 3306;
$dbName = '<OVH_DATABASE_NAME>';
$dbUser = '<OVH_MYSQL_USER>';
$dbPass = '<OVH_MYSQL_PASSWORD>';

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $dbHost,
    $dbPort,
    $dbName
);

$pdo = new PDO($dsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT            => 15, // Allow enough time for cold wake-up
]);
```

> **Note:** The first query after 5 minutes of inactivity may take 1 to 3 seconds while the Azure container and the OVH WebSocket bridge wake up on demand. Keep `PDO::ATTR_TIMEOUT` set to at least `15` seconds.
~~~
