# MySQL Reverse WebSocket Tunnel on Azure

This repository provides a one-click deployment for a **TCP-over-WebSocket** relay on **Azure Container Apps**. It exposes a standard MySQL port (`3306`) accessible via a regular PDO DSN by encapsulating traffic over an outbound WebSocket tunnel.

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
