# PGBudget Project Analysis and Improvement Recommendations

**Analysis Date:** January 2025  
**Project:** PGBudget - PostgreSQL-based Zero-Sum Budgeting System  
**Current Version:** 0.3.0

---

## Executive Summary

PGBudget is a well-architected personal finance application implementing zero-sum budgeting principles with proper double-entry accounting. The project demonstrates strong technical foundations with PostgreSQL at its core, comprehensive testing, and clear architectural patterns. However, there are significant opportunities for improvement in user experience, feature completeness, code organization, and production readiness.

**Overall Assessment:** ⭐⭐⭐⭐ (4/5)
- **Strengths:** Solid architecture, proper accounting principles, good testing coverage
- **Weaknesses:** Limited UX features, incomplete documentation, minimal frontend optimization

---

## 1. Architecture & Design Analysis

### 1.1 Strengths ✅

#### Excellent Database Architecture
- **Three-schema pattern** (`data`, `utils`, `api`) provides clear separation of concerns
- **Proper double-entry accounting** ensures mathematical accuracy
- **Row-Level Security (RLS)** for multi-tenant isolation
- **UUID-based public API** hides internal implementation details
- **Comprehensive migrations** with up/down support using Goose

#### Strong Testing Foundation
- **100+ test cases** in Go covering core functionality
- **Integration tests** with real PostgreSQL container
- **Error handling tests** for validation scenarios
- **Balance calculation tests** ensuring accuracy

#### Clear Conventions
- Well-documented coding standards in `CONVENTIONS.md`
- Consistent naming patterns across schemas
- Lowercase SQL queries for readability

### 1.2 Areas for Improvement 🔧

#### 1.2.1 Missing API Documentation
**Issue:** No OpenAPI/Swagger specification for the REST API

**Impact:** 
- Difficult for frontend developers to understand available endpoints
- No automated API testing
- Harder to maintain API consistency

**Recommendation:**
```yaml
# Create openapi.yaml
openapi: 3.0.0
info:
  title: PGBudget API
  version: 0.3.0
  description: Zero-sum budgeting API with double-entry accounting

paths:
  /ledgers:
    get:
      summary: List all ledgers for current user
      responses:
        '200':
          description: List of ledgers
          content:
            application/json:
              schema:
                type: array
                items:
                  $ref: '#/components/schemas/Ledger'
    post:
      summary: Create a new ledger
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              properties:
                name:
                  type: string
                  maxLength: 255
                description:
                  type: string
      responses:
        '201':
          description: Ledger created
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/Ledger'

components:
  schemas:
    Ledger:
      type: object
      properties:
        uuid:
          type: string
        name:
          type: string
        description:
          type: string
        created_at:
          type: string
          format: date-time
```

**Implementation Steps:**
1. Create `openapi.yaml` documenting all API endpoints
2. Add Swagger UI at `/api/docs`
3. Generate TypeScript types from OpenAPI spec
4. Add API validation middleware based on spec

**Estimated Effort:** 2-3 days

---

#### 1.2.2 Incomplete Go Backend
**Issue:** `main.go` only contains "Hello, World!" - no actual API server

**Current State:**
```go
package main

import "fmt"

func main() {
	fmt.Println("Hello, World!")
}
```

**Impact:**
- No Go-based API server (relying on PHP/PostgREST)
- Missing opportunity for type-safe API layer
- No middleware for logging, metrics, rate limiting

**Recommendation:**
Create a proper Go API server using Chi or Gin:

```go
// main.go
package main

import (
	"context"
	"log"
	"net/http"
	"os"
	"os/signal"
	"time"

	"github.com/go-chi/chi/v5"
	"github.com/go-chi/chi/v5/middleware"
	"github.com/jackc/pgx/v5/pgxpool"
)

type Server struct {
	db     *pgxpool.Pool
	router *chi.Mux
}

func NewServer(dbURL string) (*Server, error) {
	// Create database connection pool
	pool, err := pgxpool.New(context.Background(), dbURL)
	if err != nil {
		return nil, err
	}

	s := &Server{
		db:     pool,
		router: chi.NewRouter(),
	}

	// Middleware
	s.router.Use(middleware.Logger)
	s.router.Use(middleware.Recoverer)
	s.router.Use(middleware.RequestID)
	s.router.Use(middleware.RealIP)
	s.router.Use(middleware.Timeout(60 * time.Second))

	// Routes
	s.setupRoutes()

	return s, nil
}

func (s *Server) setupRoutes() {
	s.router.Route("/api/v1", func(r chi.Router) {
		// Ledgers
		r.Get("/ledgers", s.handleListLedgers)
		r.Post("/ledgers", s.handleCreateLedger)
		r.Get("/ledgers/{uuid}", s.handleGetLedger)
		r.Patch("/ledgers/{uuid}", s.handleUpdateLedger)
		r.Delete("/ledgers/{uuid}", s.handleDeleteLedger)

		// Accounts
		r.Get("/ledgers/{ledger_uuid}/accounts", s.handleListAccounts)
		r.Post("/ledgers/{ledger_uuid}/accounts", s.handleCreateAccount)
		
		// Transactions
		r.Get("/accounts/{account_uuid}/transactions", s.handleListTransactions)
		r.Post("/transactions", s.handleCreateTransaction)
		
		// Budget Status
		r.Get("/ledgers/{ledger_uuid}/budget-status", s.handleGetBudgetStatus)
	})

	// Health check
	s.router.Get("/health", func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		w.Write([]byte("OK"))
	})
}

func (s *Server) handleListLedgers(w http.ResponseWriter, r *http.Request) {
	// Implementation
}

func main() {
	dbURL := os.Getenv("DATABASE_URL")
	if dbURL == "" {
		log.Fatal("DATABASE_URL environment variable is required")
	}

	server, err := NewServer(dbURL)
	if err != nil {
		log.Fatal(err)
	}
	defer server.db.Close()

	// Start server
	srv := &http.Server{
		Addr:         ":8080",
		Handler:      server.router,
		ReadTimeout:  15 * time.Second,
		WriteTimeout: 15 * time.Second,
		IdleTimeout:  60 * time.Second,
	}

	// Graceful shutdown
	go func() {
		log.Printf("Starting server on %s", srv.Addr)
		if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatal(err)
		}
	}()

	// Wait for interrupt signal
	quit := make(chan os.Signal, 1)
	signal.Notify(quit, os.Interrupt)
	<-quit

	log.Println("Shutting down server...")
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	if err := srv.Shutdown(ctx); err != nil {
		log.Fatal("Server forced to shutdown:", err)
	}

	log.Println("Server exited")
}
```

**Benefits:**
- Type-safe API layer with Go structs
- Better performance than PHP
- Built-in middleware for logging, metrics, CORS
- Easier to deploy as single binary
- Better error handling and validation

**Estimated Effort:** 1-2 weeks

---

#### 1.2.3 No Caching Layer
**Issue:** Every request hits the database directly

**Impact:**
- Slower response times for frequently accessed data
- Higher database load
- Poor scalability

**Recommendation:**
Implement Redis caching for:
- Budget status (cache for 5 minutes)
- Account balances (cache for 1 minute)
- Ledger metadata (cache for 1 hour)
- User session data

```go
// cache/redis.go
package cache

import (
	"context"
	"encoding/json"
	"time"

	"github.com/redis/go-redis/v9"
)

type Cache struct {
	client *redis.Client
}

func NewCache(addr string) *Cache {
	return &Cache{
		client: redis.NewClient(&redis.Options{
			Addr: addr,
		}),
	}
}

func (c *Cache) GetBudgetStatus(ctx context.Context, ledgerUUID string) (interface{}, error) {
	key := "budget_status:" + ledgerUUID
	val, err := c.client.Get(ctx, key).Result()
	if err == redis.Nil {
		return nil, nil // Cache miss
	}
	if err != nil {
		return nil, err
	}

	var result interface{}
	if err := json.Unmarshal([]byte(val), &result); err != nil {
		return nil, err
	}
	return result, nil
}

func (c *Cache) SetBudgetStatus(ctx context.Context, ledgerUUID string, data interface{}, ttl time.Duration) error {
	key := "budget_status:" + ledgerUUID
	val, err := json.Marshal(data)
	if err != nil {
		return err
	}
	return c.client.Set(ctx, key, val, ttl).Err()
}

func (c *Cache) InvalidateBudgetStatus(ctx context.Context, ledgerUUID string) error {
	key := "budget_status:" + ledgerUUID
	return c.client.Del(ctx, key).Err()
}
```

**Cache Invalidation Strategy:**
- Invalidate budget status on any transaction creation/update/delete
- Invalidate account balance on transaction affecting that account
- Use cache-aside pattern (check cache first, then DB)

**Estimated Effort:** 3-4 days

---

## 2. User Experience & Features

### 2.1 Critical Missing Features (from YNAB_COMPARISON)

#### 2.1.1 Inline Budget Assignment ⭐⭐⭐⭐⭐
**Current:** Separate page to assign money  
**YNAB:** Click amount, type, Enter  
**Priority:** CRITICAL

**Implementation:**
```javascript
// public/js/budget-inline-edit.js
class InlineBudgetEditor {
    constructor() {
        this.setupEventListeners();
    }

    setupEventListeners() {
        document.querySelectorAll('.budget-amount').forEach(cell => {
            cell.addEventListener('click', (e) => this.handleCellClick(e));
        });
    }

    handleCellClick(event) {
        const cell = event.target;
        const currentAmount = cell.textContent.replace(/[^0-9.-]/g, '');
        
        // Create input field
        const input = document.createElement('input');
        input.type = 'number';
        input.value = currentAmount / 100; // Convert cents to dollars
        input.className = 'inline-budget-input';
        
        // Replace cell content with input
        cell.innerHTML = '';
        cell.appendChild(input);
        input.focus();
        input.select();

        // Handle save on Enter or blur
        const saveEdit = async () => {
            const newAmount = Math.round(parseFloat(input.value) * 100);
            const categoryUUID = cell.dataset.categoryUuid;
            const ledgerUUID = cell.dataset.ledgerUuid;

            try {
                const response = await fetch('/api/assign-to-category.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        ledger_uuid: ledgerUUID,
                        category_uuid: categoryUUID,
                        amount: newAmount,
                        description: `Budget assignment to ${cell.dataset.categoryName}`
                    })
                });

                if (response.ok) {
                    // Update UI
                    cell.textContent = this.formatCurrency(newAmount);
                    this.updateBudgetTotals();
                    this.showSuccessMessage('Budget updated');
                } else {
                    throw new Error('Failed to update budget');
                }
            } catch (error) {
                this.showErrorMessage(error.message);
                cell.textContent = this.formatCurrency(currentAmount);
            }
        };

        input.addEventListener('blur', saveEdit);
        input.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') saveEdit();
            if (e.key === 'Escape') cell.textContent = this.formatCurrency(currentAmount);
        });
    }

    formatCurrency(cents) {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD'
        }).format(cents / 100);
    }

    updateBudgetTotals() {
        // Recalculate and update budget totals
        // ...
    }

    showSuccessMessage(message) {
        // Show toast notification
    }

    showErrorMessage(message) {
        // Show error toast
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    new InlineBudgetEditor();
});
```

**Estimated Effort:** 2-3 days

---

#### 2.1.2 Move Money Between Categories ⭐⭐⭐⭐⭐
**Current:** Must create manual transactions  
**YNAB:** "Move Money" button, simple modal  
**Priority:** CRITICAL

**Database Function:**
```sql
-- migrations/20250115000001_add_move_money_function.sql
-- +goose Up
create or replace function api.move_between_categories(
    p_ledger_uuid text,
    p_from_category_uuid text,
    p_to_category_uuid text,
    p_amount bigint,
    p_date timestamptz default now(),
    p_description text default null
) returns text as $$
declare
    v_transaction_uuid text;
    v_from_balance bigint;
begin
    -- Validate amount
    if p_amount <= 0 then
        raise exception 'Move amount must be positive: %', p_amount;
    end if;

    -- Check source category has sufficient balance
    select api.get_account_balance(p_from_category_uuid) into v_from_balance;
    if v_from_balance < p_amount then
        raise exception 'Insufficient funds in source category. Available: %, Requested: %',
            v_from_balance, p_amount;
    end if;

    -- Create the move transaction (debit from, credit to)
    insert into data.transactions (
        ledger_id,
        date,
        description,
        amount,
        debit_account_id,
        credit_account_id,
        user_data
    )
    select
        l.id,
        p_date,
        coalesce(p_description, 'Move money: ' || from_cat.name || ' → ' || to_cat.name),
        p_amount,
        from_cat.id,
        to_cat.id,
        utils.get_user()
    from data.ledgers l
    join data.accounts from_cat on from_cat.uuid = p_from_category_uuid
    join data.accounts to_cat on to_cat.uuid = p_to_category_uuid
    where l.uuid = p_ledger_uuid
        and l.user_data = utils.get_user()
        and from_cat.ledger_id = l.id
        and to_cat.ledger_id = l.id
    returning uuid into v_transaction_uuid;

    if v_transaction_uuid is null then
        raise exception 'Failed to create move transaction. Check that ledger and categories exist and belong to you.';
    end if;

    return v_transaction_uuid;
end;
$$ language plpgsql volatile security invoker;

-- +goose Down
drop function if exists api.move_between_categories;
```

**UI Implementation:**
```html
<!-- Move Money Modal -->
<div id="moveMoney Modal" class="modal">
    <div class="modal-content">
        <h2>Move Money</h2>
        <form id="moveMoneyForm">
            <div class="form-group">
                <label>From Category</label>
                <select id="fromCategory" required>
                    <!-- Populated dynamically -->
                </select>
                <span class="available-amount">Available: $0.00</span>
            </div>

            <div class="form-group">
                <label>To Category</label>
                <select id="toCategory" required>
                    <!-- Populated dynamically -->
                </select>
            </div>

            <div class="form-group">
                <label>Amount</label>
                <input type="number" id="moveAmount" step="0.01" min="0.01" required>
            </div>

            <div class="form-group">
                <label>Memo (optional)</label>
                <input type="text" id="moveMemo" maxlength="255">
            </div>

            <div class="form-actions">
                <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn-primary">Move Money</button>
            </div>
        </form>
    </div>
</div>
```

**Estimated Effort:** 2-3 days

---

#### 2.1.3 Category Goals System ⭐⭐⭐⭐⭐
**Status:** Partially implemented (database schema exists)  
**Missing:** UI components, goal progress tracking, underfunded alerts

**Recommendation:**
Complete the goals implementation with:

1. **Goal Creation UI**
```javascript
// public/js/goals-manager.js
class GoalsManager {
    showGoalModal(categoryUUID) {
        const modal = document.getElementById('goalModal');
        modal.dataset.categoryUuid = categoryUUID;
        
        // Populate goal types
        this.renderGoalTypes();
        modal.style.display = 'block';
    }

    renderGoalTypes() {
        const types = [
            {
                id: 'monthly_funding',
                name: 'Monthly Funding Goal',
                description: 'Budget a fixed amount every month',
                icon: '📅',
                fields: ['target_amount']
            },
            {
                id: 'target_balance',
                name: 'Target Balance Goal',
                description: 'Save up to a total amount',
                icon: '🎯',
                fields: ['target_amount']
            },
            {
                id: 'target_by_date',
                name: 'Target by Date Goal',
                description: 'Reach a target amount by a specific date',
                icon: '📆',
                fields: ['target_amount', 'target_date']
            }
        ];

        // Render goal type cards
        // ...
    }

    async createGoal(goalData) {
        const response = await fetch('/api/goals.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(goalData)
        });

        if (!response.ok) {
            throw new Error('Failed to create goal');
        }

        return await response.json();
    }
}
```

2. **Goal Progress Indicators**
```html
<!-- In budget dashboard -->
<div class="category-row">
    <div class="category-name">
        Groceries
        <div class="goal-progress" data-goal-type="monthly_funding">
            <div class="progress-bar">
                <div class="progress-fill" style="width: 60%"></div>
            </div>
            <span class="goal-text">$300 of $500</span>
        </div>
    </div>
    <div class="category-budgeted">$300.00</div>
    <div class="category-activity">-$180.00</div>
    <div class="category-balance">$120.00</div>
</div>
```

3. **Underfunded Goals Sidebar**
```html
<div class="underfunded-goals-sidebar">
    <h3>Goals Needing Attention</h3>
    <div class="underfunded-list">
        <div class="underfunded-item">
            <span class="category-name">Emergency Fund</span>
            <span class="needed-amount">Need $200 more</span>
            <button class="btn-quick-fund">Quick Fund</button>
        </div>
    </div>
</div>
```

**Estimated Effort:** 1-2 weeks

---

### 2.2 UI/UX Improvements

#### 2.2.1 Responsive Design Issues
**Issue:** Basic responsive design, not optimized for mobile

**Recommendations:**
1. **Mobile-first CSS**
```css
/* public/css/mobile.css */
@media (max-width: 768px) {
    .budget-table {
        display: block;
        overflow-x: auto;
    }

    .category-row {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.5rem;
        padding: 1rem;
        border-bottom: 1px solid #eee;
    }

    .category-name {
        font-size: 1.1rem;
        font-weight: bold;
    }

    .category-amounts {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.5rem;
        font-size: 0.9rem;
    }

    .amount-label {
        display: block;
        color: #666;
        font-size: 0.75rem;
    }
}
```

2. **Touch-friendly interactions**
- Minimum 44px tap targets
- Swipe gestures for common actions
- Bottom navigation bar on mobile

3. **Progressive Web App (PWA)**
```json
// public/manifest.json
{
  "name": "PGBudget",
  "short_name": "PGBudget",
  "start_url": "/",
  "display": "standalone",
  "background_color": "#ffffff",
  "theme_color": "#3182ce",
  "icons": [
    {
      "src": "/icons/icon-192.png",
      "sizes": "192x192",
      "type": "image/png"
    },
    {
      "src": "/icons/icon-512.png",
      "sizes": "512x512",
      "type": "image/png"
    }
  ]
}
```

**Estimated Effort:** 1 week

---

#### 2.2.2 No Loading States or Optimistic Updates
**Issue:** UI freezes during API calls, no feedback to user

**Recommendation:**
```javascript
// public/js/ui-utils.js
class UIUtils {
    static showLoading(element) {
        element.classList.add('loading');
        element.setAttribute('aria-busy', 'true');
    }

    static hideLoading(element) {
        element.classList.remove('loading');
        element.setAttribute('aria-busy', 'false');
    }

    static async withLoading(element, asyncFn) {
        this.showLoading(element);
        try {
            return await asyncFn();
        } finally {
            this.hideLoading(element);
        }
    }

    static optimisticUpdate(element, newValue, asyncFn) {
        const oldValue = element.textContent;
        element.textContent = newValue;
        element.classList.add('optimistic');

        asyncFn()
            .then(() => {
                element.classList.remove('optimistic');
                element.classList.add('success');
                setTimeout(() => element.classList.remove('success'), 1000);
            })
            .catch((error) => {
                element.textContent = oldValue;
                element.classList.remove('optimistic');
                element.classList.add('error');
                this.showError(error.message);
            });
    }

    static showError(message) {
        const toast = document.createElement('div');
        toast.className = 'toast toast-error';
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.classList.add('fade-out');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
}
```

**Estimated Effort:** 2-3 days

---

## 3. Code Quality & Maintainability

### 3.1 Strengths ✅
- Comprehensive test coverage (100+ tests)
- Clear separation of concerns (three-schema pattern)
- Consistent naming conventions
- Good error handling in database functions

### 3.2 Areas for Improvement

#### 3.2.1 No Frontend Build Process
**Issue:** Raw JavaScript files, no bundling, no TypeScript

**Recommendation:**
Set up modern frontend tooling:

```json
// package.json
{
  "name": "pgbudget-frontend",
  "version": "0.3.0",
  "scripts": {
    "dev": "vite",
    "build": "vite build",
    "preview": "vite preview",
    "type-check": "tsc --noEmit",
    "lint": "eslint src --ext .ts,.tsx",
    "format": "prettier --write \"src/**/*.{ts,tsx,css}\""
  },
  "dependencies": {
    "react": "^18.2.0",
    "react-dom": "^18.2.0",
    "react-router-dom": "^6.20.0",
    "zustand": "^4.4.7",
    "date-fns": "^3.0.0",
    "chart.js": "^4.4.0",
    "react-chartjs-2": "^5.2.0"
  },
  "devDependencies": {
    "@types/react": "^18.2.43",
    "@types/react-dom": "^18.2.17",
    "@typescript-eslint/eslint-plugin": "^6.14.0",
    "@typescript-eslint/parser": "^6.14.0",
    "@vitejs/plugin-react": "^4.2.1",
    "autoprefixer": "^10.4.16",
    "eslint": "^8.55.0",
    "postcss": "^8.4.32",
    "prettier": "^3.1.1",
    "tailwindcss": "^3.3.6",
    "typescript": "^5.3.3",
    "vite": "^5.0.8"
  }
}
```

**Benefits:**
- TypeScript for type safety
- Modern React with hooks
- Vite for fast development
- Tailwind CSS for consistent styling
- Tree-shaking and code splitting

**Estimated Effort:** 2-3 weeks (rewrite frontend)

---

#### 3.2.2 No Linting or Code Formatting
**Issue:** Inconsistent code style, no automated checks

**Recommendation:**
```yaml
# .github/workflows/lint.yml
name: Lint

on: [push, pull_request]

jobs:
  lint-go:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: actions/setup-go@v4
        with:
          go-version: '1.21'
      - name: golangci-lint
        uses: golangci/golangci-lint-action@v3
        with:
          version: latest

  lint-sql:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Install sqlfluff
        run: pip install sqlfluff
      - name: Lint SQL
        run: sqlfluff lint migrations/

  lint-frontend:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: actions/setup-node@v3
        with:
          node-version: '18'
      - run: npm ci
      - run: npm run lint
      - run: npm run type-check
```

**Estimated Effort:** 1 day

---

#### 3.2.3 Missing Monitoring & Observability
**Issue:** No logging, metrics, or error tracking in production

**Recommendation:**
Implement comprehensive observability:

1. **Structured Logging**
```go
// logger/logger.go
package logger

import (
    "github.com/rs/zerolog"
    "github.com/rs/zerolog/log"
    "os"
)

func Init() {
    zerolog.TimeFieldFormat = zerolog.TimeFormatUnix
    
    if os.Getenv("ENV") == "production" {
        zerolog.SetGlobalLevel(zerolog.InfoLevel)
    } else {
        zerolog.SetGlobalLevel(zerolog.DebugLevel)
        log.Logger = log.Output(zerolog.ConsoleWriter{Out: os.Stderr})
    }
}

func LogRequest(method, path string, duration int64, statusCode int) {
    log.Info().
        Str("method", method).
        Str("path", path).
        Int64("duration_ms", duration).
        Int("status", statusCode).
        Msg("HTTP request")
}

func LogError(err error, context map[string]interface{}) {
    event := log.Error().Err(err)
    for k, v := range context {
        event = event.Interface(k, v)
    }
    event.Msg("Error occurred")
}
```

2. **Metrics with Prometheus**
```go
// metrics/metrics.go
package metrics

import (
    "github.com/prometheus/client_golang/prometheus"
    "github.com/prometheus/client_golang/prometheus/promauto"
)

var (
    HttpRequestsTotal = promauto.NewCounterVec(
        prometheus.CounterOpts{
            Name: "http_requests_total",
            Help: "Total number of HTTP requests",
        },
        []string{"method", "path", "status"},
    )

    HttpRequestDuration = promauto.NewHistogramVec(
        prometheus.HistogramOpts{
            Name: "http_request_duration_seconds",
            Help: "HTTP request duration in seconds",
            Buckets: prometheus.DefBuckets,
        },
        []string{"method", "path"},
    )

    DatabaseQueriesTotal = promauto.NewCounterVec(
        prometheus.CounterOpts{
            Name: "database_queries_total",
            Help: "Total number of database queries",
        },
        []string{"query_type", "status"},
    )

    ActiveUsers = promauto.NewGauge(
        prometheus.GaugeOpts{
            Name: "active_users",
            Help: "Number of currently active users",
        },
    )
)
```

3. **Error Tracking with Sentry**
```go
import "github.com/getsentry/sentry-go"

func InitSentry() {
    sentry.Init(sentry.ClientOptions{
        Dsn: os.Getenv("SENTRY_DSN"),
        Environment: os.Getenv("ENV"),
        Release: "pgbudget@" + version,
    })
}

func CaptureError(err error, context map[string]interface{}) {
    sentry
