---
goal: Dashboard Features Implementation - Admin Reporting, Seller Payouts, Financial Reports, Batch Operations
version: 1.0
date_created: 2026-03-30
last_updated: 2026-03-30
owner: Kevin Fraser (ksfraser)
status: 'Planned'
tags: [feature, dashboard, admin-reporting, seller-payouts, financial-reports, batch-operations]
---

# Phase 4-E-D: Dashboard Features Implementation Plan

**Status**: 🔵 Planned | **Effort**: 2-3 days | **LOC Target**: 2,000+ | **Tests Target**: 40+

---

## Introduction

Phase 4-E-D implements four specialized dashboard systems to provide comprehensive visibility into auction operations, settlement processes, seller payouts, and financial reporting. All dashboards use the ksfraser/html library for consistent, accessible HTML generation with Bootstrap styling and WCAG 2.1 compliance.

### Phase Overview

This phase builds upon Phase 4-E-C (SettlementDashboard) and extends it with three additional dashboard implementations before WordPress integration in Phase 4-E-E.

**Requirements Coverage**:
- REQ-DASHBOARD-ADMIN-001 through 005: Admin reporting functionality
- REQ-DASHBOARD-SELLER-001 through 004: Seller payout dashboard
- REQ-DASHBOARD-REPORTS-001 through 003: Financial reporting
- REQ-DASHBOARD-BATCH-001 through 002: Batch operations UI

---

## Architecture Overview

### System Component Diagram

```
Dashboard Layer (UI)
├── AdminReportingDashboard.php (500 LOC)
│   ├── Settlement Overview (aggregated stats)
│   ├── Seller Performance Metrics
│   ├── Revenue Analysis
│   ├── Dispute Resolution Statistics
│   └── System Health Monitoring
│
├── SellerPayoutDashboard.php (450 LOC)
│   ├── Individual Seller Stats
│   ├── Payout History & Schedules
│   ├── Commission Breakdown
│   ├── Withdrawal Requests
│   └── Payment Method Management
│
├── FinancialReportsDashboard.php (400 LOC)
│   ├── Revenue Reports
│   ├── Commission Reports
│   ├── Expense Tracking
│   ├── Financial Summary
│   └── Export Functionality
│
└── BatchOperationsDashboard.php (350 LOC)
    ├── Batch Job Queue
    ├── Execution Status
    ├── Error Handling
    ├── Retry Management
    └── Logging & Audit Trail

Service Layer (Business Logic)
├── DashboardDataService.php - Data aggregation & calculation
├── ReportGeneratorService.php - Report generation
├── ExportService.php - CSV/PDF export
└── BatchJobService.php - Batch operation orchestration

Data Access Layer
├── DashboardRepository.php - Query optimization & caching
├── ReportRepository.php - Report data access
└── BatchJobRepository.php - Batch job persistence

Database Layer
├── dashboard_views (materialized views for performance)
├── settlement_reports table
├── financial_reports table
└── batch_jobs table
```

### Component Relationships

```
AdminReportingDashboard
  └─→ DashboardDataService
      └─→ DashboardRepository
          └─→ dashboard_views (DB)

SellerPayoutDashboard
  └─→ DashboardDataService + PayoutService
      └─→ DashboardRepository + PayoutRepository

FinancialReportsDashboard
  └─→ ReportGeneratorService + ExportService
      └─→ ReportRepository + ExportRepository

BatchOperationsDashboard
  └─→ BatchJobService
      └─→ BatchJobRepository
```

---

## Feature Requirements

### 4-E-D-1: Admin Reporting Dashboard (REQ-DASHBOARD-ADMIN-001-005)

**Overview**: Comprehensive admin view of platform metrics, settlement operations, and performance indicators.

#### Functional Requirements

- **REQ-DASHBOARD-ADMIN-001**: Display aggregated settlement metrics
  - Total auctions processed (all time & this month)
  - Total settlements completed
  - Average settlement time
  - Success rate percentage
  - Total GMV (Gross Merchandise Value)

- **REQ-DASHBOARD-ADMIN-002**: Display seller performance metrics
  - Top 10 sellers by revenue
  - Seller count by status (active, inactive, suspended)
  - Average sales per seller
  - Performance trend charts (30/60/90 day views)

- **REQ-DASHBOARD-ADMIN-003**: Display revenue analysis
  - Total platform revenue breakdown
  - Commission revenue trends
  - Refund rate analysis
  - Payment processing volume

- **REQ-DASHBOARD-ADMIN-004**: Display dispute resolution statistics
  - Open disputes count
  - Resolved disputes this month
  - Average resolution time
  - Resolution success rate by type

- **REQ-DASHBOARD-ADMIN-005**: Display system health monitoring
  - API response times (avg/max/p99)
  - Database query performance
  - Payment processor status
  - Service availability uptime percentage

#### Technical Specifications

- **Data Source**: Aggregated from settlement_reports, financial_reports, and system_logs tables
- **Performance**: Cached views with 1-hour TTL for heavy queries
- **Refresh Rate**: Real-time metrics updated on page load
- **Export Options**: CSV, PDF report generation
- **Accessibility**: WCAG 2.1 AA compliant with ARIA labels

---

### 4-E-D-2: Seller Payout Dashboard (REQ-DASHBOARD-SELLER-001-004)

**Overview**: Individual seller view of their payout history, balance, and payment methods.

#### Functional Requirements

- **REQ-DASHBOARD-SELLER-001**: Display seller summary statistics
  - Current balance available for payout
  - Total earnings this month
  - Pending settlements count
  - Last payout date and amount

- **REQ-DASHBOARD-SELLER-002**: Display payout history and schedule
  - Historical payout records (table with sorting/filtering)
  - Next scheduled payout date
  - Payout frequency and schedule
  - Payout method used for each transaction

- **REQ-DASHBOARD-SELLER-003**: Display commission breakdown
  - Commission rate(s) applied
  - Total commissions paid this month
  - Commission on disputed/refunded items
  - Entry fees collected (if applicable)

- **REQ-DASHBOARD-SELLER-004**: Display withdrawal and payment management
  - Registered payment methods
  - Add/remove payment method interface
  - Withdrawal request submission
  - Withdrawal request status tracking

#### Technical Specifications

- **User Context**: Seller ID from current WordPress user
- **Data Filtering**: Show only seller's own transactions
- **Security**: Verify seller ownership before displaying data
- **Performance**: Query-specific caching (30-minute TTL)
- **Notifications**: Alert for unusual activity or failed payouts

---

### 4-E-D-3: Financial Reports Dashboard (REQ-DASHBOARD-REPORTS-001-003)

**Overview**: Detailed financial reporting for accounting and business intelligence.

#### Functional Requirements

- **REQ-DASHBOARD-REPORTS-001**: Display revenue reports
  - Daily/weekly/monthly revenue summaries
  - Revenue by auction category
  - Revenue by payment method
  - Year-over-year comparison charts

- **REQ-DASHBOARD-REPORTS-002**: Display commission and fee reports
  - Commission collected by seller
  - Platform commission rate analysis
  - Entry fees collected
  - Payment processor fees

- **REQ-DASHBOARD-REPORTS-003**: Display financial summary and exports
  - Profit & loss summary
  - Expense tracking
  - Tax calculation (if required)
  - Export with multiple formats (CSV, Excel, PDF)
  - Scheduled report generation and email delivery

#### Technical Specifications

- **Date Range Filtering**: Customizable start/end dates
- **Report Generation**: Async processing via BatchJobService
- **Caching**: Reports cached for 24 hours
- **Audit Trail**: All report access logged
- **Compliance**: Tax-compliant calculations and formats

---

### 4-E-D-4: Batch Operations Dashboard (REQ-DASHBOARD-BATCH-001-002)

**Overview**: Management interface for batch job execution, monitoring, and error handling.

#### Functional Requirements

- **REQ-DASHBOARD-BATCH-001**: Display batch job queue and status
  - List of pending/running/completed jobs
  - Job type and parameters
  - Progress indicators for long-running tasks
  - Execution start/end times
  - Time elapsed and ETA

- **REQ-DASHBOARD-BATCH-002**: Display error handling and retry controls
  - Failed job count and details
  - Error messages and stack traces
  - Manual retry button per job
  - Batch retry functionality
  - Logging and audit trail

#### Technical Specifications

- **Job Types**: Payout processing, settlement generation, report generation, export tasks
- **Monitoring**: Real-time status updates (WebSocket or polling)
- **Retry Strategy**: Exponential backoff with max 3 retries
- **Permissions**: Admin-only access
- **Logging**: Comprehensive logs stored in batch_jobs.logs column

---

## Implementation Tasks

### Phase 4-E-D-1: Admin Reporting Dashboard (1 day)

#### TASK-D1-1: Create AdminReportingDashboard.php (500 LOC)

**File**: `src/ksfraser/UI/Dashboard/AdminReportingDashboard.php`

**Subtasks**:
1. Create main `renderDashboard()` method
2. Implement `renderOverviewMetrics()` section (6 stat cards)
3. Implement `renderSellerPerformance()` section (table + chart)
4. Implement `renderRevenueAnalysis()` section (pie/bar charts)
5. Implement `renderDisputeStatistics()` section (metrics)
6. Implement `renderSystemHealth()` section (status cards)

**Requirements**:
- Use HtmlElement factory methods for all components
- Use ResponsiveLayoutTrait for responsive grid layout
- Add Bootstrap styling (table-hover, badges, alerts)
- Include ARIA labels and semantic HTML5
- Support empty state handling

**Completion Criteria**:
- ✅ All 6 sections render without errors
- ✅ HTML output contains all expected data points
- ✅ Bootstrap classes applied correctly
- ✅ ARIA labels present for accessibility

---

#### TASK-D1-2: Create DashboardDataService.php (300 LOC)

**File**: `src/ksfraser/Services/DashboardDataService.php`

**Methods**:
- `getSettlementMetrics()`: Aggregates settlement statistics
- `getSellerPerformance()`: Calculates seller metrics and rankings
- `getRevenueAnalysis()`: Analyzes revenue data
- `getDisputeStatistics()`: Computes dispute metrics
- `getSystemHealth()`: Fetches system monitoring data

**Responsibilities**:
- Query aggregation and caching
- Data calculation and transformation
- Error handling and fallback values
- Performance optimization

**Completion Criteria**:
- ✅ All methods return expected data structures
- ✅ Caching layer implemented (1 hour TTL)
- ✅ Handles missing/incomplete data gracefully

---

#### TASK-D1-3: Create AdminReportingDashboardTest.php (300 LOC)

**File**: `tests/unit/UI/Dashboard/AdminReportingDashboardTest.php`

**Test Methods** (15+):
- `testRenderDashboardReturnsHtmlString()`
- `testDashboardIncludesAllSections()`
- `testSettlementMetricsCalculatedCorrectly()`
- `testSellerPerformanceRanksCorrectly()`
- `testRevenueAnalysisAggregates()`
- `testDisputeStatisticsAccurate()`
- `testSystemHealthMonitoring()`
- `testAccessibilityAttributesPresent()`
- `testBootstrapClassesApplied()`
- `testEmptyStateHandling()`
- (5+ additional tests)

**Coverage**: 
- ✅ All rendering paths tested
- ✅ Data aggregation verified
- ✅ Error scenarios handled

---

### Phase 4-E-D-2: Seller Payout Dashboard (1 day)

#### TASK-D2-1: Create SellerPayoutDashboard.php (450 LOC)

**File**: `src/ksfraser/UI/Dashboard/SellerPayoutDashboard.php`

**Methods**:
- `renderDashboard($sellerId)`
- `renderSummaryCards($seller)` - Current balance, earnings, pending
- `renderPayoutHistory($sellerId)` - Sortable/filterable table
- `renderCommissionBreakdown($sellerId)` - Commission analysis
- `renderPaymentManagement($sellerId)` - Payment methods + withdrawal form

**Implementation Details**:
- Verify seller ID matches authenticated user
- Query seller-specific data only
- Support date range filtering
- Implement sorting and pagination

**Completion Criteria**:
- ✅ Renders without errors for valid seller
- ✅ Data isolation enforced (no cross-seller data leakage)
- ✅ Form submission for withdrawals works
- ✅ Payment method management functional

---

#### TASK-D2-2: Create PayoutDataService.php (250 LOC)

**File**: `src/ksfraser/Services/PayoutDataService.php`

**Methods**:
- `getSellerBalance($sellerId)`
- `getPayoutHistory($sellerId, $limit, $offset)`
- `getCommissionBreakdown($sellerId)`
- `getPaymentMethods($sellerId)`
- `calculateEarningsThisMonth($sellerId)`

**Security**:
- Validate seller ownership
- Use parameterized queries
- Log all access attempts

---

#### TASK-D2-3: Create SellerPayoutDashboardTest.php (250 LOC)

**Test Methods** (12+):
- `testRenderDashboardForValidSeller()`
- `testRejectsUnauthorizedSeller()`
- `testPayoutHistorySorted()`
- `testCommissionCalculatedCorrectly()`
- `testPaymentMethodsDisplayed()`
- `testWithdrawalFormPresented()`
- (6+ additional security/data tests)

---

### Phase 4-E-D-3: Financial Reports Dashboard (1 day)

#### TASK-D3-1: Create FinancialReportsDashboard.php (400 LOC)

**File**: `src/ksfraser/UI/Dashboard/FinancialReportsDashboard.php`

**Methods**:
- `renderDashboard($dateRange)`
- `renderRevenueReports($dateRange)`
- `renderCommissionReports($dateRange)`
- `renderExpenseTracking($dateRange)`
- `renderExportOptions()`

**Features**:
- Date range picker (today, this week, this month, this year, custom)
- Chart rendering (revenue trends, commission breakdown, etc.)
- Export button (CSV, Excel, PDF formats)
- Refresh button for real-time data

---

#### TASK-D3-2: Create ReportGeneratorService.php (350 LOC)

**File**: `src/ksfraser/Services/ReportGeneratorService.php`

**Methods**:
- `generateRevenueReport($dateRange)`
- `generateCommissionReport($dateRange)`
- `generateExpenseReport($dateRange)`
- `exportToCSV($report)`
- `exportToExcel($report)`
- `exportToPDF($report)`
- `generateAndEmail($report, $recipientEmail)`

**Implementation**:
- Use external libraries for export (PHP Excel, TCPDF)
- Async job submission via BatchJobService
- Email delivery after generation

---

#### TASK-D3-3: Create FinancialReportsDashboardTest.php (250 LOC)

**Test Methods** (12+):
- `testRevenueReportGeneration()`
- `testCommissionReportAccuracy()`
- `testDateRangeFiltering()`
- `testExportToCSV()`
- `testExportToExcel()`
- `testExportToPDF()`
- `testEmailDelivery()`
- (5+ additional tests)

---

### Phase 4-E-D-4: Batch Operations Dashboard (1 day)

#### TASK-D4-1: Create BatchOperationsDashboard.php (350 LOC)

**File**: `src/ksfraser/UI/Dashboard/BatchOperationsDashboard.php`

**Methods**:
- `renderDashboard()`
- `renderJobQueue()` - Active/pending jobs
- `renderCompletedJobs()` - Historical job records
- `renderErrorHandling()` - Failed jobs + retry controls
- `renderJobDetails($jobId)` - Detailed job info modal

**Features**:
- Real-time status updates
- Progress bars for long-running jobs
- Manual retry buttons
- Error message display with stack traces
- Job filtering by type/status

---

#### TASK-D4-2: Create BatchJobService.php (400 LOC)

**File**: `src/ksfraser/Services/BatchJobService.php`

**Methods**:
- `createJob($type, $params)`
- `enqueueJob($jobId)`
- `processJob($jobId)` - Execution handler
- `updateJobStatus($jobId, $status)`
- `retryJob($jobId)`
- `getJobStatus($jobId)`
- `getJobLogs($jobId)`

**Job Types**:
- `payout_processing` - Process pending payouts
- `settlement_generation` - Generate settlements
- `report_generation` - Generate financial reports
- `data_export` - Export data to file
- `data_cleanup` - Archive old records

**Retry Logic**:
- Max 3 retries with exponential backoff
- Notify admin on repeated failures

---

#### TASK-D4-3: Create BatchOperationsDashboardTest.php (250 LOC)

**Test Methods** (12+):
- `testJobQueueRenders()`
- `testJobStatusUpdates()`
- `testRetryMechanismWorks()`
- `testErrorLoggingCapturesDetails()`
- `testExponentialBackoff()`
- `testMaxRetriesEnforced()`
- (6+ additional tests)

---

## Database Schema Updates

### New Tables

#### `batch_jobs` Table
```sql
CREATE TABLE batch_jobs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    job_type VARCHAR(50) NOT NULL,
    status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
    parameters JSON NOT NULL,
    result JSON,
    logs LONGTEXT,
    retry_count INT DEFAULT 0,
    max_retries INT DEFAULT 3,
    next_retry_at TIMESTAMP NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
);
```

#### `dashboard_views` Table (Materialized View Cache)
```sql
CREATE TABLE dashboard_views (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    view_name VARCHAR(100) NOT NULL UNIQUE,
    view_data JSON NOT NULL,
    cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP,
    INDEX idx_expires_at (expires_at)
);
```

---

## Testing Strategy

### Unit Test Coverage

- **Admin Dashboard**: 15+ tests covering all sections and data aggregation
- **Seller Dashboard**: 12+ tests with security and data isolation verification
- **Financial Reports**: 12+ tests including export functionality
- **Batch Operations**: 12+ tests for job processing and retry logic

**Total**: 50+ unit tests

### Integration Tests

- Dashboard rendering with real database data
- Cross-dashboard data consistency
- Service layer integration
- Cache invalidation scenarios

### Performance Tests

- Dashboard load time < 2 seconds (with caching)
- Query optimization validation
- Cache effectiveness measurement

---

## Accessibility & Compliance

### WCAG 2.1 AA Compliance

- ✅ All dashboards use semantic HTML5
- ✅ ARIA labels on all interactive elements
- ✅ Color contrast ratios meeting WCAG standards
- ✅ Keyboard navigation support
- ✅ Screen reader compatibility

### Data Security

- ✅ SQL injection prevention (parameterized queries)
- ✅ XSS protection (HTML escaping)
- ✅ CSRF protection (WordPress nonces)
- ✅ Role-based access control
- ✅ Audit logging of sensitive data access

---

## Deliverables Summary

| Component | LOC | Tests | Status |
|-----------|-----|-------|--------|
| AdminReportingDashboard | 500 | 15+ | To Do |
| DashboardDataService | 300 | - | To Do |
| SellerPayoutDashboard | 450 | 12+ | To Do |
| PayoutDataService | 250 | - | To Do |
| FinancialReportsDashboard | 400 | 12+ | To Do |
| ReportGeneratorService | 350 | - | To Do |
| BatchOperationsDashboard | 350 | 12+ | To Do |
| BatchJobService | 400 | - | To Do |
| **TOTAL** | **3,000** | **50+** | **Planned** |

---

## Success Criteria

- ✅ All 4 dashboards render without errors
- ✅ 50+ unit tests passing at 100% code coverage
- ✅ Database schema created and migrated
- ✅ WCAG 2.1 AA compliance verified
- ✅ Performance benchmarks met (< 2 second load time)
- ✅ Security audit passed
- ✅ Documentation complete with architecture diagrams

---

## Next Steps

**After Phase 4-E-D Completion**:
1. Run full test suite → Verify all 50+ tests passing
2. Performance profiling → Ensure < 2 second load times
3. Security audit → Verify OWASP Top 10 compliance
4. Documentation review → Complete API documentation
5. **Proceed to Phase 4-E-E**: WordPress Integration

**Phase 4-E-E Dependencies**:
- All Phase 4-E-D dashboards complete
- Database schema finalized
- Service layer tested and verified
- Documentation ready for integration
