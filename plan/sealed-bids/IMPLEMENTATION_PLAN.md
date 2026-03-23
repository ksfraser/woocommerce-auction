# Sealed Bids Enhancement - Phase 3 Implementation Plan

**Version**: 1.0  
**Feature**: Sealed Bids for WooCommerce Auctions  
**Estimated Effort**: 35 development tasks  
**Status**: Planning Phase  
**Date**: March 2026

---

## Executive Summary

Sealed bidding is a private auction format where bidders submit their bids confidentially, and all bids are revealed simultaneously at a specified time. This format encourages competitive pricing without the psychological pressure of visible escalating bids and reduces auction sniping.

**Business Value**:
- Enable blind auction format (different user segment)
- Reduce bid sniping and timing-dependent bidding
- Increase bid value realization (users bid true value)
- Support B2B auction use cases
- New revenue stream from premium auction types

---

## Goal & Scope

### Goal

Implement a sealed bid auction format where participant bids remain hidden until the auction closes, after which all bids are simultaneously revealed to determine the winner based on highest bid.

### Scope

**In Scope**:
- Sealed bid auction type creation
- Encrypted bid storage
- Confidential bid submission and validation
- Multi-stage auction workflow (open → sealed → reveal)
- Bid reveal and winner determination
- Admin transparency and oversight
- User notifications and communication

**Out of Scope** (v2.0.0+):
- Second-price sealed bid auctions (Vickrey)
- Multi-round sealed bidding
- Blind English auctions (variation)
- Cryptographic bid commitments

---

## Architecture Overview

```
┌──────────────────────────────────────────────────────────────┐
│                    Sealed Bid Workflow                        │
├──────────────────────────────────────────────────────────────┤
│                                                               │
│  Stage 1: SEALED_BIDDING                                     │
│  ├─ Users view auction details (image, description)          │
│  ├─ Users CANNOT see other bids or bid count                 │
│  ├─ Users submit bids via encrypted channel                  │
│  └─ System stores bids in encrypted state                    │
│                         ↓                                     │
│  Stage 2: READY_FOR_REVEAL (at auction close time)          │
│  ├─ Automatic transition triggered                           │
│  ├─ Admin notified for manual reveal                         │
│  └─ Bids remain encrypted, not yet revealed                  │
│                         ↓                                     │
│  Stage 3: BIDS_REVEALED                                      │
│  ├─ Admin clicks "Reveal Bids" button                        │
│  ├─ System decrypts all bids                                 │
│  ├─ Winner determined (highest bid)                          │
│  ├─ Losers notified (outbid)                                 │
│  └─ Winner notified (won auction)                            │
│                         ↓                                     │
│  Stage 4: COMPLETED                                          │
│  ├─ Auction marked complete                                  │
│  ├─ All bids visible to admin + bidders                      │
│  └─ Winner can proceed to checkout                           │
│                                                               │
└──────────────────────────────────────────────────────────────┘

System Architecture:

┌─────────────────────────────────────────────────────────────┐
│                    Frontend Layer                           │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ Sealed Bid Auction Product Template                  │  │
│  │ - "Submit Sealed Bid" form (bid amount only)         │  │
│  │ - NO visibility of other bids or count               │  │
│  │ - Confirmation of bid submission                     │  │
│  │ - "Your Bid" status (only shows user's bid status)   │  │
│  └──────────────────────────────────────────────────────┘  │
└────────────────────────┬─────────────────────────────────────┘
                         │ AJAX (HTTPS/TLS/encrypt)
                         ↓
┌─────────────────────────────────────────────────────────────┐
│                      API Layer                              │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ POST /submit_sealed_bid - Accept encrypted bids      │  │
│  │ GET  /sealed_bid_status - Check user's bid status    │  │
│  │ POST /reveal_sealed_bids - Admin reveal (backend)    │  │
│  └──────────────────────────────────────────────────────┘  │
└────────────────────────┬─────────────────────────────────────┘
                         │
                         ↓
┌─────────────────────────────────────────────────────────────┐
│         Business Logic Layer (Services)                     │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ SealedBidService                                     │  │
│  │ - submitSealedBid()                                  │  │
│  │ - getSealedBidStatus()                               │  │
│  │ - revealAllBids()                                    │  │
│  │ - determinedWinner()                                 │  │
│  └──────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ EncryptionManager                                    │  │
│  │ - encryptBid(amount)                                 │  │
│  │ - decryptBid(encrypted_data)                         │  │
│  │ - rotateEncryptionKeys()                             │  │
│  └──────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ SealedBidValidator                                   │  │
│  │ - validateBidAmount()                                │  │
│  │ - validateAuctionState()                             │  │
│  │ - validateNonce()                                    │  │
│  └──────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ AuctionStateManager                                  │  │
│  │ - transitionToReady()                                │  │
│  │ - transitionToReveal()                               │  │
│  │ - transitionToComplete()                             │  │
│  └──────────────────────────────────────────────────────┘  │
└────────────────────────┬─────────────────────────────────────┘
                         │
                         ↓
┌─────────────────────────────────────────────────────────────┐
│      Data Access Layer (Repository Pattern)                 │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ SealedBidRepository                                  │  │
│  │ - create() - store encrypted bid                     │  │
│  │ - getEncrypted() - retrieve encrypted                │  │
│  │ - getAllForAuction() - for reveal process            │  │
│  │ - update() - update bid status                       │  │
│  └──────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ AuctionStateRepository                               │  │
│  │ - getCurrentState()                                  │  │
│  │ - updateState()                                      │  │
│  │ - getAuditTrail()                                    │  │
│  └──────────────────────────────────────────────────────┘  │
└────────────────────────┬─────────────────────────────────────┘
                         │
                         ↓
┌─────────────────────────────────────────────────────────────┐
│              Database Layer                                 │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ wp_wc_auction_sealed_bids                           │  │
│  │ wp_wc_auction_states                                │  │
│  │ wp_wc_auction_reveal_keys                           │  │
│  │ wp_wc_auction_encryption_log                        │  │
│  └──────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

---

## Database Schema

### Table: `wp_wc_auction_sealed_bids`

```sql
CREATE TABLE wp_wc_auction_sealed_bids (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sealed_bid_id VARCHAR(36) NOT NULL UNIQUE,
  auction_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  encrypted_bid LONGBLOB NOT NULL,
  encryption_key_id VARCHAR(50) NOT NULL,
  bid_hash VARCHAR(64) NOT NULL,
  decrypted_bid DECIMAL(10,2),
  bid_status VARCHAR(20) NOT NULL DEFAULT 'SEALED',
  placement_timestamp DATETIME NOT NULL,
  ip_address VARCHAR(45),
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  revealed_at DATETIME,
  KEY idx_auction_user (auction_id, user_id),
  KEY idx_auction_status (auction_id, bid_status),
  KEY idx_user_status (user_id, bid_status),
  CONSTRAINT fk_auction FOREIGN KEY (auction_id) REFERENCES wp_posts(ID),
  CONSTRAINT fk_user FOREIGN KEY (user_id) REFERENCES wp_users(ID)
);
```

**Columns**:
- `id` - Primary key
- `sealed_bid_id` - UUID for external reference
- `auction_id` - Product/auction ID
- `user_id` - Bidder user ID
- `encrypted_bid` - Encrypted bid amount using AES-256-GCM
- `encryption_key_id` - Reference to encryption key used
- `bid_hash` - SHA-256 hash of original bid (for audit)
- `decrypted_bid` - Plaintext bid (only after reveal)
- `bid_status` - SEALED, REVEALED, WINNER, OUTBID
- `placement_timestamp` - When bid was submitted
- `ip_address` - Bidder IP for audit trail
- `created_at` - Record creation
- `updated_at` - Last update
- `revealed_at` - When bid was decrypted

### Table: `wp_wc_auction_states`

```sql
CREATE TABLE wp_wc_auction_states (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  auction_id BIGINT UNSIGNED NOT NULL UNIQUE,
  auction_type VARCHAR(20) NOT NULL DEFAULT 'SEALED',
  current_state VARCHAR(50) NOT NULL,
  previous_state VARCHAR(50),
  state_changed_at DATETIME NOT NULL,
  sealed_until DATETIME,
  reveal_triggered_by BIGINT UNSIGNED,
  reveal_timestamp DATETIME,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_auction_state (auction_id, current_state),
  CONSTRAINT fk_auction FOREIGN KEY (auction_id) REFERENCES wp_posts(ID)
);
```

### Table: `wp_wc_auction_encryption_keys`

```sql
CREATE TABLE wp_wc_auction_encryption_keys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  key_id VARCHAR(50) NOT NULL UNIQUE,
  key_hash VARCHAR(64) NOT NULL,
  algorithm VARCHAR(20) NOT NULL DEFAULT 'AES-256-GCM',
  rotation_status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL,
  rotated_at DATETIME,
  deactivated_at DATETIME,
  KEY idx_key_status (rotation_status),
  CONSTRAINT uc_key_hash UNIQUE (key_hash)
);
```

---

## API Endpoints

### 1. Submit Sealed Bid

**Endpoint**: `POST /wp-admin/admin-ajax.php?action=submit_sealed_bid`

**Request**:
```json
{
  "auction_id": 456,
  "bid_amount": 250.00,
  "nonce": "security_nonce"
}
```

**Process** (client-side):
1. Generate encryption nonce/IV
2. Encrypt bid amount using public encryption key
3. Send encrypted bid to server

**Server Response (Success)**:
```json
{
  "success": true,
  "sealed_bid_id": "sb-uuid-789",
  "message": "Sealed bid submitted successfully",
  "bid_status": "SEALED",
  "encryption_confirmatio": true
}
```

**Possible Errors**:
- `AUCTION_NOT_SEALED` - Auction is not a sealed bid type
- `AUCTION_NOT_ACTIVE` - Auction is not in SEALED_BIDDING state
- `INVALID_BID_AMOUNT` - Bid fails validation
- `DUPLICATE_BID` - User already submitted bid for this auction
- `INSUFFICIENT_BALANCE` - (if payment required)

### 2. Check Sealed Bid Status

**Endpoint**: `GET /wp-admin/admin-ajax.php?action=sealed_bid_status&sealed_bid_id=sb-uuid-789`

**Response**:
```json
{
  "success": true,
  "status": "SEALED",
  "submitted_at": "2026-03-22T10:15:00Z",
  "auction_state": "SEALED_BIDDING",
  "message": "Your sealed bid has been submitted and confirmed."
}
```

**Note**: Response never reveals encrypted or decrypted bid amount until reveal phase.

### 3. Reveal All Sealed Bids (Admin Only)

**Endpoint**: `POST /wp-admin/admin-ajax.php?action=reveal_sealed_bids`

**Request**:
```json
{
  "auction_id": 456,
  "nonce": "admin_nonce",
  "confirm_reveal": true
}
```

**Response**:
```json
{
  "success": true,
  "message": "All bids revealed successfully",
  "highest_bid": 300.00,
  "winner_id": 123,
  "total_bids": 5,
  "bids_revealed": [
    {
      "user_id": 123,
      "bid_amount": 300.00,
      "status": "WINNER"
    },
    {
      "user_id": 456,
      "bid_amount": 250.00,
      "status": "OUTBID"
    }
  ]
}
```

---

## Implementation Phases

### Phase 1: Encryption Infrastructure & Security (Tasks 1-10)

**Deliverable**: Encryption system, key management, audit logging

1. **Task 1**: Design encryption architecture (AES-256-GCM)
2. **Task 2**: Implement `EncryptionManager` service
3. **Task 3**: Create key rotation mechanism
4. **Task 4**: Implement encryption key storage (wp_wc_auction_encryption_keys)
5. **Task 5**: Create audit logging for all encryption operations
6. **Task 6**: Implement key versioning and backward compatibility
7. **Task 7**: Create secure key derivation function (PBKDF2)
8. **Task 8**: Implement IV/nonce generation for each bid
9. **Task 9**: Create encryption tests and security validation
10. **Task 10**: Penetration testing for encryption layer

**Acceptance Criteria**:
- ✅ AES-256-GCM encryption used for all bids
- ✅ Keys never stored in plaintext
- ✅ Key rotation every 90 days
- ✅ No security vulnerabilities found in pen testing
- ✅ 100% encryption coverage on sensitive bid data

---

### Phase 2: Database & State Management (Tasks 11-18)

**Deliverable**: Database schema, state transitions, audit trail

11. **Task 11**: Create `wp_wc_auction_sealed_bids` table migration
12. **Task 12**: Create `wp_wc_auction_states` table migration
13. **Task 13**: Create `wp_wc_auction_encryption_keys` table migration
14. **Task 14**: Implement `SealedBidRepository` with CRUD operations
15. **Task 15**: Implement `AuctionStateRepository` with state tracking
16. **Task 16**: Create `AuctionStateManager` for state transitions
17. **Task 17**: Implement audit trail logging
18. **Task 18**: Create repository and state management tests

**Acceptance Criteria**:
- ✅ All tables created with proper relationships
- ✅ State transitions validated (SEALED → READY → REVEALED → COMPLETED)
- ✅ Audit trail captures all state changes
- ✅ 100% test coverage

---

### Phase 3: Business Logic (Tasks 19-27)

**Deliverable**: Sealed bid submission, validation, reveal logic

19. **Task 19**: Implement `SealedBidService::submitSealedBid()`
20. **Task 20**: Implement `SealedBidValidator` for bid validation
21. **Task 21**: Implement bid encryption during submission
22. **Task 22**: Implement `SealedBidService::getSealedBidStatus()`
23. **Task 23**: Implement `SealedBidService::revealAllBids()` with decryption
24. **Task 24**: Implement winner determination logic
25. **Task 25**: Implement notification system (winner/outbid)
26. **Task 26**: Implement automatic state transitions (cron-based)
27. **Task 27**: Create comprehensive business logic tests

**Acceptance Criteria**:
- ✅ Bids accepted and encrypted correctly
- ✅ Reveal process decrypts all bids correctly
- ✅ Winner determined accurately
- ✅ Notifications sent to all participants
- ✅ 100% test coverage

---

### Phase 4: API Layer (Tasks 28-31)

**Deliverable**: AJAX endpoints for sealed bid operations

28. **Task 28**: Implement `submit_sealed_bid` AJAX endpoint
29. **Task 29**: Implement `sealed_bid_status` AJAX endpoint
30. **Task 30**: Implement `reveal_sealed_bids` admin endpoint
31. **Task 31**: Create API validation and security tests

**Acceptance Criteria**:
- ✅ All endpoints return proper JSON responses
- ✅ Nonce verification on all protected endpoints
- ✅ Admin-only endpoints properly restricted
- ✅ Rate limiting to prevent abuse

---

### Phase 5: UI & Frontend (Tasks 32-35)

**Deliverable**: User interface for sealed bid submission

32. **Task 32**: Create sealed bid auction template
33. **Task 33**: Implement JavaScript client-side encryption
34. **Task 34**: Create sealed bid status display UI
35. **Task 35**: Create admin reveal interface and confirmation

**Acceptance Criteria**:
- ✅ Users can easily submit sealed bids
- ✅ Bid amount never visible to others during sealing phase
- ✅ Status clearly shown as "Your bid is sealed"
- ✅ Admin interface simple and clear for reveal action

---

## Technical Considerations

### Encryption Strategy

**Algorithm**: AES-256-GCM (Authenticated Encryption with Associated Data)

**Why AES-256-GCM**:
- 256-bit key provides quantum resistance
- GCM mode provides authenticity checking
- NIST-approved algorithm
- Hardware acceleration available (AES-NI)

**Implementation**:
```
Encryption Process:
  1. Generate random IV (16 bytes)
  2. Encrypt bid using master key + IV
  3. Compute authentication tag
  4. Store: IV + ciphertext + tag + key_id
  
Decryption Process:
  1. Verify authentication tag
  2. Retrieve master key by key_id
  3. Decrypt ciphertext using IV + key
  4. Validate decrypted value is numeric
```

### State Machine

```
States:
  - INITIALIZATION: Auction created but not yet accepting bids
  - SEALED_BIDDING: Accepting secret bids (visible to bidders only)
  - READY_FOR_REVEAL: Awaiting admin reveal, no new bids accepted
  - BIDS_REVEALED: All bids decrypted, winner determined
  - COMPLETED: Auction complete, proceeds to checkout

Transitions:
  INITIALIZATION → SEALED_BIDDING (on auction start time)
  SEALED_BIDDING → READY_FOR_REVEAL (on auction end time)
  READY_FOR_REVEAL → BIDS_REVEALED (admin click or scheduled)
  BIDS_REVEALED → COMPLETED (automatic)
```

### Security Considerations

1. **Key Management**:
   - Master encryption key stored separately from application
   - Keys rotated every 90 days
   - Old keys retained for decryption of old bids
   - No keys stored in database or logs

2. **Bid Confidentiality**:
   - Encrypted bids cannot be read without master key
   - Database compromise doesn't expose bid amounts
   - Even server admins cannot see sealed bids
   - Bid count visible (operational need, privacy trade-off)

3. **Audit Trail**:
   - Log all bid submissions with timestamp and IP
   - Log all reveal attempts with admin ID
   - Log all key rotations
   - Maintain immutable audit log

4. **Admin Oversight**:
   - Reveal operation requires admin confirmation
   - Reveal creates audit log entry
   - Cannot unrevel bids once revealed
   - Reveal timestamp recorded

### Performance Considerations

1. **Encryption Overhead**:
   - Client-side encryption adds 10-50ms
   - Server-side decryption adds 20-100ms per bid
   - Acceptable during non-critical phases

2. **Database Performance**:
   - Index on (auction_id, user_id) for duplicate check
   - Index on (auction_id, bid_status) for reveal
   - Hash-based lookups to find entries
   - Pagination for large reveal operations

3. **Reveal Performance**:
   - Batch decryption to optimize
   - Cache decrypted values temporarily
   - Estimate: 100 bids revealed in <2 seconds

---

## Testing Strategy

### Unit Tests (100% coverage required)

Suites:
- EncryptionManager tests (20 tests)
- AuctionStateManager tests (15 tests)
- SealedBidService tests (18 tests)
- SealedBidValidator tests (12 tests)
- Repository tests (20 tests)
- Total: 85+ tests

### Integration Tests

Scenarios:
- End-to-end sealed bid submission
- Multiple sealed bids on same auction
- Sealed bid reveal and winner determination
- State transitions with timing
- Encryption/decryption round-trip
- Total: 15+ tests

### Security Tests

- Encryption strength validation
- Key management verification
- No plaintext bid leakage
- SQL injection prevention
- XSS protection in bid display

---

## Compliance & Regulations

**Considerations**:
- Audit trail for regulatory compliance
- Data retention policies (keep encrypted bids for 7 years)
- GDPR right to delete (user-submitted bids)
- PCI compliance (if payment required)

---

## Success Metrics

| Metric | Target | Measurement |
|--------|--------|-------------|
| Bid encryption coverage | 100% | All sealed bids encrypted |
| Reveal accuracy | 100% | All bids decrypted correctly |
| Performance overhead | <200ms | Encryption + decryption time |
| User adoption | >10% | Sealed auctions created / total |
| Security incidents | 0 | No unauthorized bid access |

---

## Risk Assessment

| Risk | Probability | Impact | Mitigation |
|-----|-----------|--------|-----------|
| Encryption key compromise | Low | Critical | HSM storage, key rotation |
| Reveal timing attacks | Low | Medium | Constant-time decryption |
| Database performance | Medium | Medium | Proper indexing + caching |
| User confusion | High | Low | Clear UI + documentation |
| Regulatory audit | Medium | Medium | Complete audit trail |

---

**Prepared by**: Development Team  
**Next Step**: Create GitHub issues from this implementation plan
