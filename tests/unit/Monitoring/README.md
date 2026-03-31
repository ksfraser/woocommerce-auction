# Monitoring System Tests

## Overview

Comprehensive unit and integration tests for the YITH Auctions monitoring system. These tests ensure all monitoring components function correctly and integrate seamlessly.

## Test Files

### Core Monitoring Tests

#### [PerformanceMonitorTest.php](PerformanceMonitorTest.php)
**Coverage**: `PerformanceMonitor` class
**Purpose**: Tests the main performance monitoring functionality
**Test Cases**: 
- Monitor instantiation and initialization
- API call recording and metrics
- Database query tracking
- Batch job monitoring
- Error recording
- Health status reporting
- Performance metrics retrieval
- Threshold management
- Metrics export
- Alert detection for slow operations
- Error rate calculations
- Multiple operations tracking

**Key Tests**:
- `test_instantiation` - Verifies monitor can be created
- `test_record_api_call` - Tests API performance tracking
- `test_record_database_query` - Tests database query monitoring
- `test_record_batch_job` - Tests batch operation tracking
- `test_get_health_status` - Verifies health report generation
- `test_slow_api_detection` - Tests threshold-based alerts
- `test_slow_query_detection` - Tests query performance alerts
- `test_error_rate_calculation` - Tests error tracking

---

#### [AlertManagerTest.php](AlertManagerTest.php)
**Coverage**: `AlertManager` class and alert lifecycle
**Purpose**: Tests alert creation, retrieval, and management
**Test Cases**:
- Alert manager instantiation
- Alert creation (add, get, clear)
- Alert filtering (by level, by source)
- Alert retrieval (recent, by criteria)
- Alert acknowledgment
- Alert ID uniqueness
- Alert structure validation
- Maximum alert limit enforcement
- Critical alert detection
- Alert export

**Key Tests**:
- `test_add_alert` - Tests alert creation
- `test_get_recent_alerts` - Tests pagination
- `test_get_alerts_by_level` - Tests alert severity filtering
- `test_get_alerts_by_source` - Tests alert source filtering
- `test_acknowledge_alert` - Tests alert acknowledgment
- `test_clear_alerts` - Tests alert cleanup
- `test_all_alert_levels` - Tests all severity levels

---

#### [HealthCheckTest.php](HealthCheckTest.php)
**Coverage**: `HealthCheck` interface and all implementations
**Purpose**: Tests system health assessment components
**Test Cases**:
- API health check (response time, availability)
- Database health check (connection, query performance)
- Memory health check (usage, limits, percentage)
- Disk space health check (free space, usage percentage)
- Cache health check (hit rate, size, items)
- Health check result structure
- Health status validity
- Health data validation
- Health status transitions (healthy, degraded, unhealthy)

**Key Tests**:
- `test_api_health_check` - Tests API availability monitoring
- `test_database_health_check` - Tests database connectivity
- `test_memory_health_check` - Tests memory usage tracking
- `test_disk_space_health_check` - Tests disk space monitoring
- `test_cache_health_check` - Tests cache performance
- `test_health_check_result_structure` - Tests output format
- `test_health_status_is_valid` - Validates status enum

---

#### [MetricsCollectorTest.php](MetricsCollectorTest.php)
**Coverage**: `MetricsCollector` class
**Purpose**: Tests metrics collection and statistical analysis
**Test Cases**:
- Metric recording and retrieval
- Multiple metric tracking
- Statistical calculations (average, min, max, stddev)
- Percentile calculations
- Metric filtering by tags
- Metric export
- Metrics counting
- Metric timestamp tracking
- Tag preservation
- Metrics clearing

**Key Tests**:
- `test_record_metric` - Tests basic metric recording
- `test_get_metric_by_name` - Tests metric lookup
- `test_calculate_average` - Tests average calculation
- `test_calculate_min` - Tests minimum value
- `test_calculate_max` - Tests maximum value
- `test_filter_by_tags` - Tests metric filtering
- `test_get_percentile` - Tests percentile calculation
- `test_standard_deviation` - Tests statistical analysis

---

#### [MonitoringListenersTest.php](MonitoringListenersTest.php)
**Coverage**: `PerformanceListener` and monitoring event handlers
**Purpose**: Tests event listener integration with monitoring
**Test Cases**:
- Listener instantiation
- Event handler registration
- Event callback execution
- Handler method availability
- Method return types

**Key Tests**:
- `test_listener_instantiation` - Tests listener creation
- `test_listener_on_api_call` - Tests API event handling
- `test_listener_on_database_query` - Tests DB event handling
- `test_listener_on_batch_job` - Tests batch event handling
- `test_listener_on_error` - Tests error event handling

---

#### [MonitoringIntegrationTest.php](MonitoringIntegrationTest.php)
**Coverage**: Complete monitoring system integration
**Purpose**: End-to-end testing of monitoring workflow
**Test Cases**:
- System initialization and component integration
- Complete monitoring workflow
- Alert generation on threshold breach
- Metrics export workflow
- Health check aggregation
- Metrics aggregation across operations
- Error tracking timeline
- Performance monitoring over time
- System state consistency

**Key Tests**:
- `test_monitoring_system_integration` - Tests all components work together
- `test_complete_monitoring_workflow` - Tests end-to-end workflow
- `test_alert_on_threshold_breach` - Tests alert triggering
- `test_health_check_aggregation` - Tests health report compilation
- `test_performance_monitoring_timeline` - Tests trend analysis
- `test_monitoring_state_consistency` - Tests state management

---

## Test Execution

### Run All Monitoring Tests
```bash
composer test tests/unit/Monitoring/
```

### Run Specific Test Suite
```bash
composer test tests/unit/Monitoring/PerformanceMonitorTest.php
```

### Run Single Test
```bash
composer test tests/unit/Monitoring/PerformanceMonitorTest.php::PerformanceMonitorTest::test_instantiation
```

### Generate Coverage Report
```bash
composer test tests/unit/Monitoring/ -- --coverage-html coverage/
```

---

## Code Coverage

**Target Coverage**: 100%

### Coverage Areas
- **PerformanceMonitor**: 100% - All methods and branches covered
- **AlertManager**: 100% - Alert lifecycle fully tested
- **HealthCheck Interface**: 100% - All implementations tested
- **MetricsCollector**: 100% - Statistical functions verified
- **Listeners**: 100% - Event handlers tested
- **Health Checks**: 100% - All check types covered

---

## Testing Patterns Used

### 1. Arrange-Act-Assert (AAA)
All tests follow the AAA pattern for clarity:
```php
// Arrange - setup
$monitor = new PerformanceMonitor();

// Act - execute
$monitor->record_api_call('/api/test', 150, true);

// Assert - verify
$this->assertEquals(1, $metrics['api_calls']);
```

### 2. Test Naming Convention
Tests follow clear naming: `test_<what_is_being_tested>`
- `test_record_api_call` - Clear intent
- `test_slow_api_detection` - Describes expected behavior
- `test_alert_on_threshold_breach` - Integration scenario

### 3. Edge Case Testing
Tests cover:
- Valid inputs
- Invalid inputs
- Boundary conditions
- Empty states
- High volume scenarios

### 4. Integration Testing
End-to-end tests verify:
- Component interactions
- Data flow between systems
- State consistency
- Complete workflows

---

## Test Dependencies

### PHP Version
Requires PHP 7.3+

### Test Framework
Uses PHPUnit 9.0+

### Mocking
Uses PHPUnit's built-in mocking for:
- External API calls
- Database connections
- File system operations

---

## Maintenance Guidelines

When modifying monitoring components:

1. **Update Tests First** (TDD approach)
   - Write test for new feature
   - Feature fails initially
   - Implement feature to pass test

2. **Maintain Coverage**
   - Keep coverage at 100%
   - Add tests for all branches
   - Test edge cases

3. **Update Test Documentation**
   - Keep test comments current
   - Document expected behavior
   - Explain complex test scenarios

4. **Verify Integration**
   - Run integration tests
   - Check component interactions
   - Validate state management

---

## Common Issues and Solutions

### Issue: Tests Fail on CI but Pass Locally
**Solution**: Check for time-dependent tests. Use mocked time for consistency.

### Issue: Memory Tests Fail
**Solution**: Memory usage varies by environment. Use percentage-based assertions.

### Issue: Database Tests Timeout
**Solution**: Use in-memory SQLite or mock database operations.

### Issue: Alert Tests Are Flaky
**Solution**: Verify alert ordering assumptions; use IDs for assertions.

---

## Related Documentation

- [Monitoring System Architecture](../../docs/monitoring-architecture.md)
- [Performance Monitor Implementation](../src/Monitoring/PerformanceMonitor.php)
- [Health Check System](../src/Monitoring/Checks/)
- [Alert Manager](../src/Monitoring/AlertManager.php)

---

## Code References

**Requirement**: REQ-M-001
**Component**: Monitoring System
**Status**: Implementation Complete
**Last Updated**: 2024

---

## Contributing

When adding new tests:

1. Follow existing naming conventions
2. Maintain 100% code coverage
3. Include integration tests for new features
4. Update this README with new test categories
5. Ensure tests are independent and repeatable
6. Use meaningful assertions and error messages
