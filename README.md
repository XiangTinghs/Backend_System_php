# Backend System PHP

基於 **Laravel 13、PHP、MySQL 與 Docker** 建立的企業內部後端管理系統。

本專案以實際企業內部系統為開發情境，著重於 RESTful API 設計、資料庫操作、身分驗證、權限管理及自動化測試，並持續改善系統的可維護性與擴充性。

## Tech Stack

| Category | Technology |
|---|---|
| Backend | PHP / Laravel 13 |
| Database | MySQL |
| Web Server | Nginx |
| Containerization | Docker / Docker Compose |
| API | RESTful API |
| Authentication | Token-based Authentication |
| Testing | Laravel Feature Tests |
| Version Control | Git / GitHub |

## Project Architecture

本專案採用 Docker Compose 管理開發環境，並將 Laravel 應用程式與 Docker 設定分離。

```text
Backend_System_php/
├── docker/
│   ├── nginx/
│   │   └── default.conf       # Nginx 設定
│   └── php/
│       └── Dockerfile        # PHP 環境建置
├── src/                      # Laravel Application
│   ├── app/                  # Application Logic
│   ├── bootstrap/            # Application Bootstrap
│   ├── config/               # Configuration
│   ├── database/             # Migrations / Seeders
│   ├── routes/               # Route Definitions
│   └── tests/                # Automated Tests
├── .gitignore
├── docker-compose.yml        # Docker Services
└── README.md                 # Project Documentation
```

### Application Design

Laravel 應用程式依照職責分離：

- **Controller**：處理 HTTP Request 與 API Response。
- **Form Request**：驗證 API 輸入資料。
- **Middleware**：處理身分驗證及存取控制。
- **Model**：透過 Eloquent ORM 操作資料庫。
- **Migration**：管理資料庫結構。
- **Feature Test**：驗證 API 行為及預期結果。

## Features

### Implemented

- [x] Laravel 專案初始化
- [x] Docker 開發環境建置
- [x] MySQL 資料庫整合
- [x] 管理員帳號資料結構
- [x] 管理員登入 API
- [x] Token 身分驗證
- [x] Request Validation
- [x] Automated Feature Tests

### Planned

- [ ] 管理員帳號管理功能
- [ ] Role-Based Access Control（RBAC）
- [ ] API 操作紀錄
- [ ] Redis 快取整合
- [ ] API Documentation
- [ ] CI/CD Pipeline

## Development Environment

使用 Docker Compose 建立本機開發環境。

### Start Containers

```bash
docker compose up -d --build
```

### Check Container Status

```bash
docker compose ps
```

### Stop Containers

```bash
docker compose down
```

## Testing

本專案使用 Laravel 內建測試工具執行自動化測試。

在 Laravel 應用程式環境內執行：

```bash
php artisan test
```

測試主要涵蓋 API 輸入驗證、管理員登入及身分驗證等功能。

## Development Goals

- 建立具備可維護性及擴充性的 RESTful API。
- 實作企業內部管理系統常見功能。
- 透過 Docker 統一開發環境。
- 使用自動化測試驗證核心功能。
- 持續改善程式架構與開發流程。

## Project Status

**Under Development**

本專案持續開發中，功能與技術文件將隨開發進度更新。
