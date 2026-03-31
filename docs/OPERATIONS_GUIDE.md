# Operations Guide - YITH Auctions

**Document Version**: 1.0  
**Last Updated**: 2026-03-30  
**Status**: Production Ready  
**Requirement Reference**: REQ-OPS-001 (AGENTS.md - Operations & Deployment)

---

## Table of Contents

1. [Operations Overview](#operations-overview)
2. [Daily Operations](#daily-operations)
3. [Weekly Tasks](#weekly-tasks)
4. [Monthly Tasks](#monthly-tasks)
5. [On-Call Procedures](#on-call-procedures)
6. [Incident Classification & Response](#incident-classification--response)
7. [Performance Monitoring](#performance-monitoring)
8. [Capacity Planning](#capacity-planning)
9. [Runbooks](#runbooks)
10. [Escalation Matrix](#escalation-matrix)

---

## Operations Overview

### Operational Model

**Team Structure** (REQ-OPS-001):

```
Operations Manager
├─ Site Reliability Engineer (SRE) - Production
│  ├─ Infrastructure management
│  ├─ Database administration
│  ├─ Monitoring & alerting
│  └─ Performance optimization
├─ Support Lead - Customer Support
│  ├─ Tier 1: Frontline support
│  ├─ Tier 2: Escalation support
│  └─ Tier 3: Engineering support
└─ DevOps Engineer
   ├─ CI/CD pipelines
   ├─ Infrastructure as Code
   ├─ Deployment automation
   └─ Backup & recovery
```

### Shift Coverage

**On-Call Schedule** (REQ-OPS-002):

```
Monday - Friday: 9 AM - 6 PM (Business hours)
  ├─ Operations Manager: On-site
  ├─ SRE: Monitoring
  └─ Support Lead: Available

Thursday - Monday: On-call rotation
  ├─ SRE: Primary on-call (24/7, 3-week rotation)
  ├─ DevOps: Secondary (escalation)
  └─ Manager: Standby (emergency only)

Holidays & Weekends: Full team standby mode
```

---

## Daily Operations

### Morning Checklist (8:00 AM daily)

**Pre-Work Verification** (REQ-OPS-003):

```
[ ] 1. System Health Check
      ├─ Dashboard: All green (no critical alerts)
      ├─ Database: Connected & responding
      ├─ API: Health check endpoint responding (200 OK)
      ├─ Email queue: < 100 unsent messages
      └─ Uptime: (previous 24h) = 100%

[ ] 2. Error Log Review
      ├─ Check last 24h error logs
      ├─ Identify recurring errors
      ├─ Create tickets for new issues
      └─ Send summary to team

[ ] 3. Performance Review
      ├─ Page load time: p95 < 100ms
      ├─ API response: p95 < 50ms
      ├─ Error rate: < 0.5%
      ├─ Transaction success rate: > 99%
      └─ Database query time: p95 < 100ms

[ ] 4. Backup Verification
      ├─ Last backup: < 24 hours old
      ├─ Backup status: SUCCESS
      ├─ Backup size: As expected
      └─ Restore test: Monthly (last done: DATE)

[ ] 5. Security Review
      ├─ Failed login attempts: < 100
      ├─ No unusual traffic patterns
      ├─ WAF alerts: Review & analyze
      └─ SSL certificate: > 30 days to expiration
```

### Mid-Day Monitoring (12:00 PM)

**Hourly Checks** (REQ-OPS-004):

```
[ ] Error rate: Check trending (should be stable)
[ ] Active users: Check traffic patterns
[ ] Database connections: Monitor pool usage
[ ] Disk space: Ensure > 20% free
[ ] API latency: Verify p95 compliance
```

### End-of-Day Report (5:30 PM)

**Daily Summary** (REQ-OPS-005):

Document in Operations Log:
- System uptime %
- Incidents/errors occurred
- Key metrics (users, auctions, transactions)
- Changes deployed today
- Alerts received & actions taken

---

## Weekly Tasks

### Monday Tasks

**Weekly Standup Meeting (10:00 AM)** (REQ-OPS-006):

1. **Status Update** (each team member)
   - Past week: Incidents, issues resolved
   - Current week: Planned deployments
   - Blockers: Any escalations needed

2. **Metrics Review**
   - System uptime (last week)
   - Customer complaints (if any)
   - Performance trends
   - Capacity utilization

3. **Operational Planning**
   - Schedule maintenance windows
   - Plan capacity additions
   - Priority incidents/bugs

### Wednesday Tasks

**Performance Review (2:00 PM)** (REQ-OPS-007):

```
[ ] Database Performance
    ├─ Slow query log review (> 1 second queries)
    ├─ Index usage analysis
    ├─ Table maintenance (VACUUM/ANALYZE)
    └─ Connection pool health

[ ] Application Performance
    ├─ Top pages by response time
    ├─ Top API endpoints by latency
    ├─ Memory usage trends
    └─ Cache hit ratios

[ ] Cost Analysis
    ├─ AWS/hosting costs
    ├─ Data transfer costs
    ├─ Resource utilization efficiency
    └─ Cost optimization opportunities
```

### Friday Tasks

**Weekly Compliance & Security (3:00 PM)** (REQ-OPS-008):

```
[ ] Security Review
    ├─ Failed login attempts: < 100
    ├─ Suspicious IP activity
    ├─ SSL certificate status
    └─ WAF logs for patterns

[ ] Backup Verification
    ├─ Week's backups: All successful
    ├─ Storage & retention: Verified
    └─ Restore test: Execute and document

[ ] Documentation Update
    ├─ Runbooks: Up to date?
    ├─ Incident logs: Documented?
    ├─ Configuration changes: Recorded?
    └─ Known issues: Updated?

[ ] Week-end Report
    ├─ Uptime summary
    ├─ Incidents summary
    ├─ User metrics
    └─ Next week priorities
```

---

## Monthly Tasks

### First Week: System Audit

**Comprehensive System Review** (REQ-OPS-009):

```
[ ] Infrastructure Audit
    ├─ Server specifications (CPU/RAM/Disk)
    ├─ Network configuration
    ├─ Load balancer settings
    ├─ Database replication status
    └─ Disaster recovery readiness

[ ] Application Audit
    ├─ Dependency versions
    ├─ Configuration consistency
    ├─ Known issues status
    └─ Code quality metrics

[ ] Access Audit
    ├─ User access rights (RBAC)
    ├─ Admin account activity
    ├─ API key rotation status
    └─ SSH key management

[ ] Compliance Check
    ├─ Data protection (encryption, retention)
    ├─ Audit logs: Complete & accessible
    ├─ Security patches: All applied
    └─ Regulatory compliance: On track
```

### Second Week: Capacity Planning

**Growth & Scaling Analysis** (REQ-OPS-010):

| Metric | Current | Growth Rate | Forecast |
|--------|---------|-------------|----------|
| Concurrent users | 500 | +50/month | 2,000 by Dec |
| Database size | 10 GB | +100MB/day | 50 GB by Dec |
| Daily transactions | 1,000 | +100/day | 5,000 by Dec |
| Auctions active | 5,000 | +500/day | 20,000 by Dec |

**Forecasting & Actions**:
```
IF growth continues at current rate:
  ├─ Q2 2026: Add 2 API server instances
  ├─ Q3 2026: Upgrade database memory (current: 8GB → 16GB)
  ├─ Q4 2026: Implement database sharding for bids table
  └─ Q1 2027: Add CDN edge locations
```

### Third Week: Security Audit

**Security Assessment** (REQ-OPS-011):

```
[ ] Vulnerability Scanning
    ├─ SAST: Static code analysis (phpstan)
    ├─ Dependency check: Composer audit
    ├─ Configuration audit: Security settings
    └─ Manual code review: Random samples

[ ] Access Review
    ├─ Active admin accounts: Justify all
    ├─ API keys: Rotation due?
    ├─ Database users: Least privilege check
    └─ SSH keys: Validity & ownership

[ ] Incident Review
    ├─ Past month incidents: Root cause analysis
    ├─ Lessons learned: Apply to process
    ├─ Prevention measures: Implemented?
    └─ Documentation: Updated runbooks
```

### Fourth Week: Planning & Optimization

**Process Improvement & Planning** (REQ-OPS-012):

```
[ ] Performance Optimization
    ├─ Query optimization: Top 10 slow queries
    ├─ Caching opportunities: Identified?
    ├─ Database indexes: Unused indexes to remove?
    └─ Memory profiling: Leaks identified?

[ ] Operational Planning
    ├─ Next month priorities
    ├─ Scheduled maintenance: Plan deployments
    ├─ Knowledge sharing: Training needed?
    └─ Tooling: Improvements to testing/monitoring?

[ ] Documentation
    ├─ Runbooks: Updated & tested
    ├─ Playbooks: Up to date?
    ├─ Architecture docs: Current?
    └─ Operational metrics: Baselines recorded
```

---

## On-Call Procedures

### On-Call Responsibilities

**Primary On-Call SRE** (REQ-OPS-013):

- **Response time**: 15 minutes for high/critical alerts
- **Availability**: Available 24/7 during rotation
- **Communication**: Phone call or SMS for emergencies
- **Escalation**: If unable to resolve in 30 minutes

**On-Call Preparation**:
```
Before shift starts (5 PM Thursday):
  ├─ Read incident log from previous week
  ├─ Review known issues list
  ├─ Verify monitoring alerts are functioning
  └─ Test escalation procedures

During shift:
  ├─ Monitor dashboard (background)
  ├─ Respond to alerts within SLA
  ├─ Document all actions taken
  └─ Handoff to next person (9 AM Monday)

After shift (end of rotation):
  ├─ Write summary report
  ├─ Update runbooks with lessons learned
  └─ Brief next on-call person
```

### Alert Response Workflow

**Alert Received** (REQ-OPS-014):

```
1. ACKNOWLEDGE (within 5 min)
   └─ Click "Acknowledge" in monitoring system

2. ASSESS (5-10 min)
   ├─ What is alerting? (metric/threshold/component)
   ├─ Impact: Critical/High/Medium/Low?
   ├─ Scope: How many users affected?
   └─ Look at graphs, logs, and error rate

3. INVESTIGATE (10-20 min)
   ├─ Check recent deployments
   ├─ Review error logs
   ├─ Check system metrics
   ├─ Run diagnostic commands
   └─ Consult runbooks

4. RESPOND (varies by issue)
   ├─ If fixable: Apply fix
   ├─ If escalation needed: Engage team
   ├─ If emergency: Follow incident playbook
   └─ Update status in incident tracker

5. RESOLVE & DOCUMENT (end)
   ├─ Verify alert cleared & metrics normal
   ├─ Document root cause
   ├─ Record resolution steps
   ├─ Identify follow-up actions
   └─ Update runbook if needed
```

---

## Incident Classification & Response

### Severity Levels

**Incident Classification Matrix** (REQ-OPS-015):

| Severity | User Impact | Response Time | Example |
|----------|------------|---|---|
| **P1 - Critical** | All users / data loss | 15 min | Database down, data corruption |
| **P2 - High** | Many users / partial feature | 30 min | API returning errors, bidding broken |
| **P3 - Medium** | Some users / workaround exists | 2 hours | Slow loading, some users see errors |
| **P4 - Low** | Minor / cosmetic issue | 1 business day | UI formatting, non-critical feature |

### Incident Response Playbook

**Incident Response Process** (REQ-OPS-016):

```
┌─ INCIDENT DETECTED
│  ├─ Alert fired OR customer report
│  └─ Classify initial severity
│
├─ ACTIVATE / ESCALATE
│  ├─ P1/P2: Page on-call SRE (if off-hours)
│  ├─ P1: Page Manager (if off-hours)
│  └─ Create incident ticket
│
├─ TRIAGE & INVESTIGATION
│  ├─ Assess true impact
│  ├─ Gather logs & metrics
│  ├─ Identify root cause (or likely)
│  └─ Update severity if needed
│
├─ COMMUNICATION
│  ├─ Notify stakeholders
│  ├─ Post status updates every 15 min
│  └─ Set expectation on ETA
│
├─ REMEDIATION
│  ├─ Implement fix (or workaround)
│  ├─ Test thoroughly (if time allows)
│  └─ Deploy/apply fix
│
├─ VERIFICATION
│  ├─ Verify fix worked
│  ├─ Monitor for recurrence
│  ├─ Clear alert/incidents
│  └─ Update status to "Resolved"
│
└─ POST-INCIDENT
   ├─ Root cause analysis (next business day)
   ├─ Update runbooks
   ├─ Schedule preventive work
   └─ Share learnings with team
```

### Escalation Flow

**Escalation Path** (REQ-OPS-017):

```
Primary On-Call SRE (TRY to resolve for 30 min)
        ↓ if unable
Secondary: DevOps Engineer (Try different approach)
        ↓ if unable
Tertiary: Engineering Manager (May involve dev team)
        ↓ if unable
Manager: Operations Manager (Strategic decisions)
        ↓ if critical
CEO: Executive notification (P1 > 2 hours)
```

---

## Performance Monitoring

### Key Metrics Dashboard

**Real-time Monitoring** (REQ-OPS-018):

```
System Health Dashboard
├─ Uptime: 99.97% (target: 99.9%)
├─ Error rate: 0.2% (target: < 0.5%)
├─ Active users: 234 / 500 capacity
|
API Performance
├─ Response time (p95): 45ms (target: < 50ms)
├─ Requests/sec: 85 (capacity: 100)
├─ Error rate: 0.1% (target: < 0.5%)
|
Database Health
├─ Query time (p95): 80ms (target: < 100ms)
├─ Connections: 45 / 100 (capacity)
├─ Replication lag: 0.5s (target: < 1s)
|
Business Metrics
├─ Active auctions: 4,523
├─ Bids per hour: 1,243
├─ Revenue (today): $12,403
```

### Alert Configuration

**Alert Thresholds** (REQ-OPS-019):

| Metric | Warning | Critical | Check Interval |
|--------|---------|----------|---|
| CPU usage | > 70% | > 85% | 1 min |
| Memory usage | > 75% | > 90% | 1 min |
| Disk space | > 80% full | > 95% full | 5 min |
| Error rate | > 1% | > 5% | 1 min |
| API latency (p95) | > 100ms | > 200ms | 1 min |
| DB latency (p95) | > 150ms | > 300ms | 1 min |
| Replication lag | > 5s | > 30s | 10 sec |

---

## Capacity Planning

### Growth Forecasting

**Scenarios** (REQ-OPS-020):

```
SCENARIO 1: Gradual Growth (Business as typical)
  ├─ User growth: +50 concurrent/month
  ├─ Data growth: +100 GB/year
  ├─ Action: Scale servers Q3 2026
  └─ Budget: ~$5K/month additional

SCENARIO 2: Viral Growth (Marketing success)
  ├─ User growth: +500 concurrent/month
  ├─ Data growth: +1 TB/year
  ├─ Action: Scale immediately, upgrade database
  └─ Budget: ~$50K/month additional

SCENARIO 3: Contraction (Competition/market change)
  ├─ User decline: -20%/month
  ├─ Action: Scale down, consolidate servers
  └─ Cost savings: ~$2K/month reduction
```

### Scaling Triggers

**Auto-Scaling Rules** (REQ-OPS-021):

```
IF CPU > 70% for 5 consecut. minutes
  THEN Scale: Add 1 API server instance

IF Memory > 80% for 5 minutes
  THEN Alert: Need to optimize or scale

IF Disk space < 20%
  THEN Alert: Cleanup or expand storage

IF API latency (p95) > 100ms for 10 minutes
  THEN Scale: Add API server OR check for slow queries
```

---

## Runbooks

### Runbook: Database is Down

**Symptom**: Database connection refused (REQ-OPS-022)

```
STEP 1: Verify connectivity (2 min)
  $ mysql -h db.prod.local -u app_user -p
  ERROR: Can't connect to MySQL server
  
STEP 2: Check database service status (1 min)
  $ systemctl status mysql
  → If stopped: systemctl start mysql
  → If failed: Check logs: /var/log/mysql/error.log

STEP 3: Verify disk space on database server (1 min)
  $ df -h /var/lib/mysql
  → If < 10% free: URGENT - expand or clean up
  
STEP 4: Check replication status (1 min)
  $ SHOW SLAVE STATUS\G
  → If running: Normal
  → If error: Check Seconds_Behind_Master
  
STEP 5: If unable to recover in 15 minutes:
  ESCALATE to Senior DBA
```

### Runbook: High CPU Usage

**Symptom**: CPU > 85% for > 5 minutes (REQ-OPS-023)

```
STEP 1: Identify process consuming CPU (2 min)
  $ top -b -n 1 | head -20
  
STEP 2: If PHP is high:
  ├─ Check active connections: SHOW PROCESSLIST;
  ├─ Kill long-running queries: KILL query_id;
  └─ Check error logs for bugs

STEP 3: If MySQL is high:
  ├─ Check slow query log
  ├─ Run EXPLAIN on slow queries
  └─ Add indexes if needed

STEP 4: If not identified in query:
  ├─ Check running processes
  ├─ Stop non-critical background jobs
  └─ Monitor trends

STEP 5: If persists > 30 min:
  ESCALATE to Engineering Manager
```

### Runbook: High Disk Usage

**Symptom**: Disk usage > 85% (REQ-OPS-024)

```
STEP 1: Identify large files/directories (2 min)
  $ du -sh /* | sort -rh | head -10
  
STEP 2: Check log files (primary culprit):
  $ du -sh /var/log/*
  → Rotate old logs: logrotate -f /etc/logrotate.conf
  → Archive old MySQL logs
  
STEP 3: Check database size:
  $ SELECT table_schema, sum(data_length+index_length)/1024/1024 FROM information_schema.tables GROUP BY table_schema;
  → Archive old auction data if > 80% limit
  
STEP 4: Clean up temporary files:
  $ rm -rf /tmp/yith-auctions/*
  $ mysql --execute "PURGE BINARY LOGS BEFORE DATE_SUB(NOW(), INTERVAL 7 DAY);"
  
STEP 5: Expand disk if unable to free space:
  ESCALATE to AWS/Infrastructure team
```

---

## Escalation Matrix

**24/7 Contact Information** (REQ-OPS-025):

| Role | Name | Phone | Email | On-Call |
|------|------|-------|-------|---------|
| **SRE** | [Name] | [Phone] | [Email] | Yes |
| **DevOps** | [Name] | [Phone] | [Email] | Secondary |
| **Operations Mgr** | [Name] | [Phone] | [Email] | Emergency |
| **Engineering Lead** | [Name] | [Phone] | [Email] | Dev escalation |
| **CEO** | [Name] | [Phone] | [Email] | P1 > 2h |

**Escalation Criteria**:

```
ESCALATE TO SRE SECONDARY within 30 min if:
  ├─ Issue not resolved
  ├─ Root cause unclear
  ├─ Need second opinion

ESCALATE TO OPERATIONS MANAGER if:
  ├─ Issue unresolved > 1 hour
  ├─ Data integrity concern
  ├─ Business decision needed

ESCALATE TO ENGINEERING LEAD if:
  ├─ Application bug causing incident
  ├─ Need code changes
  ├─ Architecture review needed

NOTIFY CEO immediately if:
  ├─ Data breach suspected
  ├─ Revenue-impacting outage > 30 min
  ├─ Media-worthy incident
```

---

## Revision History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2026-03-30 | Initial operations guide |

---

**Operations Manager**: [Name]  
**Last Updated**: 2026-03-30  
**Next Review**: 2026-06-30  
**Emergency Contact**: [24/7 number]
