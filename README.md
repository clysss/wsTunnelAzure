# MySQL Reverse WebSocket Tunnel on Azure

This repository provides a one-click deployment for a **TCP-over-WebSocket** relay on **Azure Container Apps**. It exposes a standard MySQL port (`3306`) accessible via a regular PDO DSN by encapsulating traffic over an outbound WebSocket tunnel.


## php file on your server
Upload mysql_tunnel.php to your OVH public web folder (or better to a subfolder) and rename it.

update the 5 configurations parameters

## One-Click Deployment

Click the button below to automatically deploy the infrastructure to your Azure subscription:

[![Deploy to Azure](https://aka.ms/deploytoazurebutton)](https://portal.azure.com/#create/Microsoft.Template/uri/https%3A%2F%2Fraw.githubusercontent.com%2Fclysss%2FwsTunnelAzure%2Frefs%2Fheads%2Fmain%2Fazuredeploy.json)

## Deployment Parameters

| Parameter | Description | Example |
| :--- | :--- | :--- |
| **App Name** | Unique name for the Azure Container Apps instance | `mysql-tunnel-relay` |
| **Tunnel Secret** | Shared secret key used to authenticate the incoming tunnel connection | `your-long-secret-key` |
| **Location** | Azure deployment region (defaults to the resource group's location) | `francecentral` |

## Deployed Architecture

```text
[PHP Client (PDO)] ──(TCP : 3306)──> [Azure Container Apps] <══(WSS : 443)══ [Source Server] ──(TCP : 3306)──> [MySQL]


## Testing & Troubleshooting

Follow these three steps to verify that the wake-on-demand tunnel works end-to-end.

### 1. Test the OVH Wake Endpoint
Open your browser or run `curl` against your OVH endpoint using your configured `WAKE_KEY`:

```bash
curl -i "[https://yourdomain.com/mysql_tunnel.php?key=WAKE_KEY](https://yourdomain.com/mysql_tunnel.php?key=WAKE_KEY)"
```
* **Expected output:** `HTTP/1.1 200 OK` with body `OK` (calling it without a valid key should return `403 Forbidden`).

### 2. Test the Azure Relay Ports
From your client server (or local machine), check that both TCP ports (`8080` for WebSocket and `3306` for MySQL) are reachable on your Azure Container Instance FQDN (found in **Deployment > Outputs > `tunnelHost`**):

```bash
nc -zv mysql-tunnel-relay-xxxx.francecentral.azurecontainer.io 8080 3306
```
* **Expected output:** `succeeded!` on both ports.

### 3. Test the End-to-End PDO Connection
Run a quick one-line PHP check on your client server to trigger a cold wake-up and run a query against your OVH database:

```bash
php -r '
$pdo = new PDO(
    "mysql:host=mysql-tunnel-relay-xxxx.francecentral.azurecontainer.io;port=3306;dbname=YOUR_DB_NAME;charset=utf8mb4",
    "YOUR_OVH_DB_USER",
    "YOUR_OVH_DB_PASSWORD",
    [PDO::ATTR_TIMEOUT => 10]
);
echo "Connected! MySQL version: " . $pdo->query("SELECT VERSION()")->fetchColumn() . PHP_EOL;
'
```
* **Expected output:** After a ~0.5–1s cold-start delay on the first connection, it prints `Connected! MySQL version: 8.0.x` (or `5.7.x`). Subsequent queries within the next 25 seconds execute immediately.

### Common Issues
* **Connection hangs or times out (`SQLSTATE[HY000] [2002]`):** Verify that `$AZURE_HOST` inside `/www/mysql_tunnel.php` matches the exact `tunnelHost` output from Azure (without `https://` or port `:8080`), and that `$TUNNEL_SECRET` is identical on both sides.
* **Connection immediately closed:** Check Azure container logs (`az container logs -g <resource-group> -n mysql-tunnel-relay`) or verify that `WAKE_URL` in Azure uses `https://` and includes `?key=...` matching `$WAKE_KEY`.
* **OVH `500` error on wake:** Ensure your OVH `.ovhconfig` is set to **PHP 8.1, 8.2, or 8.3** (PHP 7.x is not supported).
