# Project Rename Reference - WooCommerce Auction → WooCommerce Auction

**Date**: March 2026  
**Status**: Phase 1 Complete (infrastructure), Phase 2 Pending (documentation)

---

## Naming Convention Reference

Use this document as a reference when updating code or documentation.

### PHP Class Names
| Old Pattern | New Pattern | Example |
|-------------|-------------|---------|
| `YITH_Auctions` | `WcAuction_` | `WcAuction_Product`, `WcAuction_Bids` |
| `WcAuction_*` | `WcAuction_*` | `WcAuction_BidIncrement`, `WcAuction_Ajax` |

### PHP Namespaces
| Old | New |
|-----|-----|
| `YITH\Auctions` | `WC\Auction` |
| `YITH\Auctions\Tests` | `WC\Auction\Tests` |

### WordPress Hooks
| Old Pattern | New Pattern | Notes |
|-------------|-------------|-------|
| `WcAuction_*` | `wc_auction_*` | Filters and actions |
| `yith_auction_*` | `wc_auction_*` | Product-specific hooks |

### Database Elements
| Element | Old | New | Notes |
|---------|-----|-----|-------|
| **Database name** | `yith_auctions` | `woocommerce_auction` | In `.env`, configs |
| **Table prefix** | `wp_WcAuction_*` | `wp_wc_auction_*` | NEW code only |
| **Meta key prefix** | `_yith_auction_*` | Keep legacy for compatibility | Existing data |
| **Meta key prefix** | `_WcAuction_*` | `_wc_auction_*` | NEW code uses WC |

### Docker Components
| Old | New |
|-----|-----|
| `yith-auctions-db` | `woocommerce-auction-db` |
| `yith-auctions-wordpress` | `woocommerce-auction-wordpress` |
| `yith-auctions-nginx` | `woocommerce-auction-nginx` |
| `yith-auctions-*` | `woocommerce-auction-*` |
| `yith-network` | `woocommerce-auction-network` |
| `yith-auctions:latest` | `woocommerce-auction:latest` |

### File & Directory Names
| Old | New |
|-----|-----|
| `yith-auctions-for-woocommerce` | `woocommerce-auction` |
| `yith-auctions` (plugin folder) | `woocommerce-auction` |

### Package & Repository
| Old | New |
|-----|-----|
| `yith/auctions-for-woocommerce` | `ksfraser/woocommerce-auction` |
| `https://github.com/*/yith-auctions-for-woocommerce` | `https://github.com/ksfraser/woocommerce-auction` |

### Configuration
| Old | New |
|-----|-----|
| `DB_NAME=yith_auctions` | `DB_NAME=woocommerce_auction` |
| `SITE_TITLE=WooCommerce Auction Development` | `SITE_TITLE=WooCommerce Auction Development` |

---

## What's Been Updated ✅

### Phase 1: Infrastructure (COMPLETE)
- [x] composer.json (package name, namespaces)
- [x] .env.example (database, site title)
- [x] docker-compose.yml (all container names, network, volumes)
- [x] Dockerfile (comments)
- [x] docker/ configuration files (mysql-init, php.ini, nginx.conf, etc.)
- [x] .github/workflows/ci-cd.yml (workflow names, docker image names)
- [x] .phpmd.xml (ruleset name)
- [x] phpcs.xml.dist (standard name)
- [x] README.md (title, clone URL, fork notice)
- [x] DOCKER-SETUP.md (title, URLs)
- [x] CONTRIBUTING.md (title)

---

## What Remains ⏳

### Phase 2: Extensive Documentation (PENDING)

These files have 40-100+ references each and need comprehensive updates:

#### 📘 Architecture & Component Docs
- [ ] `docs/Project_Architecture_Blueprint.md` (~50+ refs)
  - All references to YITH components
  - Class names in diagrams and text
  - Component descriptions

- [ ] `docs/components/YITH_Auctions-coordinator-documentation.md` (~80+ refs)
  - Rename file to `WcAuction_Coordinator-documentation.md`
  - Update all class references
  - Update UML diagrams and descriptions

- [ ] `docs/components/WC_Product_Auction-model-documentation.md` (~40+ refs)
  - Class name references
  - Interface descriptions

- [ ] `docs/components/WcAuction_Bids-repository-documentation.md` (~40+ refs)
  - Rename class references
  - Update repository pattern descriptions

#### 📋 Project Specification Files
- [ ] `docs/spec-auction-technical-requirements.md` (~30+ refs)
  - Requirements mentioning YITH
  - Interface specifications
  - Acceptance criteria

#### 📝 Feature Implementation Plans
- [ ] `docs/plans/FEATURE_AUTO_BIDDING_PLAN.md` (~20+ refs)
- [ ] `docs/plans/FEATURE_SEALED_BIDS_PLAN.md` (~20+ refs)
- [ ] `docs/plans/FEATURE_ENTRY_FEES_PLAN.md` (~20+ refs)

#### ✓ Business & Requirements
- [ ] `docs/Business_Requirements_Document.md` (~20+ refs)
- [ ] `docs/Functional_Requirements_Document.md` (~30+ refs)
- [ ] `docs/Non_Functional_Requirements.md` (~15+ refs)

#### 🗂️ Other Documentation
- [ ] `CHANGELOG.md` (3 refs - easy, just version notes)
- [ ] `PROJECT-STATUS.md` (30+ refs)
- [ ] `LOCAL-RUNNER-SETUP.md` (multiple URL references)

#### 🧪 Test Files
- [ ] `tests/bootstrap.php` (5 refs - namespaces, constants)
- [ ] `tests/unit/*.php` (40+ refs total)
  - Namespace declarations
  - Mock class names
  - Assertion messages

---

## Quick Update Script (For Documentation)

### Python/Bash Script to Update Multiple Files (Template)

```bash
#!/bin/bash
# Update all markdown and PHP documentation files

replace_in_files() {
    local pattern="$1"
    local replacement="$2"
    local file_pattern="$3"
    
    find . -name "$file_pattern" -type f -exec sed -i "s/$pattern/$replacement/g" {} \;
    echo "Replaced '$pattern' with '$replacement' in files matching '$file_pattern'"
}

# Class name replacements
replace_in_files "YITH_Auctions" "WcAuction_" "*.md"
replace_in_files "WcAuction_BidIncrement" "WcAuction_BidIncrement" "*.md"
replace_in_files "WcAuction_Bids" "WcAuction_Bids" "*.md"
replace_in_files "YITH\\\\Auctions\\\\Tests" "WC\\\\Auction\\\\Tests" "*.php"
replace_in_files "YITH\\\\Auctions" "WC\\\\Auction" "*.php"

# Namespace replacements in code
replace_in_files "namespace YITH" "namespace WC\\\\Auction" "tests/*.php"

# Everything done!
echo "✅ Documentation update complete"
```

### Or Use GNU sed Directly

```bash
# Update all Markdown files
find docs/ -name "*.md" -type f | xargs sed -i \
  -e 's/YITH_Auctions/WcAuction_/g' \
  -e 's/WcAuction_/WcAuction_/g' \
  -e 's/YITH\\Auctions/WC\\Auction/g' \
  -e 's/WcAuction_/wc_auction_/g' \
  -e 's/yith_auction_/wc_auction_/g'

# Update test files
find tests/ -name "*.php" -type f | xargs sed -i \
  -e 's/YITH\\Auctions\\Tests/WC\\Auction\\Tests/g' \
  -e 's/YITH\\Auctions/WC\\Auction/g'
```

---

## How to Complete Phase 2

### Option A: Manual Updates (Recommended for Quality)
1. Use this mapping document as reference
2. Update files one category at a time
3. Verify changes with grep/search
4. Commit by document category

### Option B: Automated Updates (If Using sed/awk)
```bash
# Create a backup first
git stash

# Apply replacements
git ls-files | xargs sed -i 's/YITH_Auctions/WcAuction_/g'
# ... (other replacements)

# Review changes
git diff

# Commit
git add -A
git commit -m "refactor: Update documentation from YITH to WooCommerce Auction"
```

---

## Testing After Updates

### Verify No Broken Links
```bash
# Check for references to old GitHub URLs
grep -r "yith-auctions-for-woocommerce" docs/
grep -r "yith-auctions-for-woocommerce" tests/

# Should return: (empty)
```

### Verify Namespaces Updated in PHP
```bash
grep -r "namespace YITH" tests/
grep -r "namespace WC\\\\Auction" tests/

# Should show all test files have WC\Auction namespace
```

### Check Database References
```bash
grep -r "yith_auctions" docs/
# Should be minimal (only legacy references noted)
```

---

## Preservation Notes

✅ **Keep these unchanged** (legacy YITH code):
- All files in `includes/` directory
- References to original YITH components in documentation about legacy code
- Database tables for existing YITH data
- Meta keys for existing YITH data (`_yith_*`)

📝 **Log these decisions** when updating:
- Why we're keeping `_yith_*` for legacy data
- When new code should use `_wc_auction_*` instead
- Migration paths for old data to new formats

---

## Commit Strategy for Phase 2

Suggested commit order:
1. **docs/components/\*** - Component documentation (3 commits)
2. **docs/spec-*.md** - Specification files (1 commit)
3. **Project documentation** - README docs, requirements (1 commit)
4. **Feature plans** - Implementation plans (1 commit)
5. **Test files** - Unit & integration tests (1 commit)
6. **Remaining docs** - Changelog, status (1 commit)

**Each commit should include**: what was updated + mapping reference

---

## Environment Variable Checklist

When updating documentation, ensure these are correctly referenced:
- [ ] `DB_NAME` → `woocommerce_auction`
- [ ] `SITE_TITLE` → "WooCommerce Auction"
- [ ] Container names → `woocommerce-auction-*`
- [ ] Network → `woocommerce-auction-network`
- [ ] Plugin slug → `woocommerce-auction`
- [ ] Package name → `ksfraser/woocommerce-auction`

---

## Fork Information

**Original Repository**: [WooCommerce Auction](https://yithemes.com/themes/plugins/yith-woocommerce-auctions/)  
**Fork Maintained By**: ksfraser  
**GitHub**: https://github.com/ksfraser/woocommerce-auction  
**Purpose**: Community-maintained fork with independent feature development

This document serves as a single reference point for all naming changes during the fork transition.

**Last Updated**: March 2026  
**Phase**: 1 of 2 Complete (Infrastructure 100%, Documentation 0%)
