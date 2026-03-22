# Contributing to WooCommerce Auction

Thank you for your interest in contributing! This document outlines the process for contributing code, documentation, and improvements.

---

## Getting Started

### Prerequisites
- PHP 7.3+
- Composer
- Git
- PHPUnit (installed via Composer)
- WordPress installation for manual testing

### Setup Development Environment

```bash
# Clone repository
git clone https://github.com/ksfraser/woocommerce-auction.git
cd woocommerce-auction

# Install dependencies
composer install

# Verify tests run
vendor/bin/phpunit

# Verify code quality tools
vendor/bin/phpstan --version
vendor/bin/phpmd --version
vendor/bin/phpcs --version
```

---

## Development Workflow

### 1. Create Feature Branch

```bash
# Update main
git checkout main
git pull origin main

# Create feature branch
git checkout -b feature/your-feature-name
```

**Branch Naming**:
- `feature/` - New features
- `fix/` - Bug fixes
- `refactor/` - Code improvements
- `docs/` - Documentation
- `test/` - Test additions

### 2. Write Tests First (TDD)

All new code MUST have tests BEFORE implementation.

```bash
# Create test file in appropriate location
vim tests/Unit/ksfraser/YourFeature/YourClassTest.php
```

**Test Structure**:
```php
namespace ksfraser\Tests\YourFeature;

use PHPUnit\Framework\TestCase;
use ksfraser\MockWordPress\TestCase as WPTU_TestCase;
use ksfraser\YourFeature\YourClass;

class YourClassTest extends WPTU_TestCase {
    /**
     * @test
     * Tests should be clear, specific, and well-documented
     */
    public function testDescriptionOfWhatItTests() {
        // Arrange: Set up test data
        $input = new SomeObject();
        
        // Act: Execute code under test
        $result = $input->doSomething();
        
        // Assert: Verify results
        $this->assertEquals('expected', $result);
    }
}
```

**Run Tests**:
```bash
# Watch tests fail (RED phase)
vendor/bin/phpunit tests/Unit/ksfraser/YourFeature/YourClassTest.php

# All tests should fail initially
```

### 3. Implement Feature

Create your implementation to make tests pass.

**File Locations**:
- New PHP classes: `includes/ksfraser/FeatureName/ClassName.php`
- New templates: `templates/ksfraser/[admin|frontend]/template-name.php`
- New interfaces: `includes/interface/YourInterface.php`

**Code Standards**:

```php
namespace ksfraser\FeatureName;

use ksfraser\Common\Exception\InvalidArgumentException;

/**
 * Class ClassName
 * 
 * @package ksfraser\FeatureName
 * @requirement REQ-FEATURE-001
 * @author Kevin Fraser <kevin@ksfraser.ca>
 * @since 1.3.0
 */
class ClassName {
    /**
     * Public method description
     * 
     * @param string $param Parameter description
     * @return array Return value description
     * @throws InvalidArgumentException If param is invalid
     * @requirement REQ-FEATURE-001.1
     */
    public function publicMethod(string $param): array {
        // Implementation
    }
    
    /**
     * Private helper method description
     * 
     * @internal
     */
    private function helperMethod(): void {
        // Implementation
    }
}
```

**Requirements**:
- ✅ Namespace: `ksfraser\`
- ✅ PSR-4 autoloading
- ✅ Full PHPDoc blocks
- ✅ Type hints (all parameters and returns)
- ✅ Requirement tags in docblocks
- ✅ Single Responsibility Principle
- ✅ Dependency injection (constructor)

### 4. Verify Tests Pass

```bash
# Watch tests pass (GREEN phase)
vendor/bin/phpunit tests/Unit/ksfraser/YourFeature/YourClassTest.php

# Should show 100% of tests passing
```

### 5. Refactor & Optimize

```bash
# Improve code quality (REFACTOR phase)
# - Extract helper methods
# - Eliminate duplication
# - Improve naming
# - Add edge case tests

# Re-run tests to ensure nothing broke
vendor/bin/phpunit tests/Unit/ksfraser/YourFeature/
```

### 6. Code Quality Checks

```bash
# Static analysis
vendor/bin/phpstan analyse includes/ksfraser/

# Complexity & mess detection
vendor/bin/phpmd includes/ksfraser/ text cleancode

# Style check (PSR-2)
vendor/bin/phpcs --standard=PSR2 includes/ksfraser/

# Fix style issues automatically
vendor/bin/phpcbf --standard=PSR2 includes/ksfraser/
```

### 7. Coverage Validation

```bash
# Generate coverage report
vendor/bin/phpunit --coverage-html coverage/

# Open in browser
open coverage/index.html

# Requirement: ≥ 100% for ksfraser code
```

### 8. Commit Changes

Use conventional commit format:

```bash
# Stage changes
git add .

# Commit with message
git commit -m "feat(BidIncrement): Add support for price-based bid ranges

- Implement BidIncrementManager for managing ranges
- Add BidIncrementValidator for validation
- Add tests achieving 100% coverage
- Closes #123"
```

**Commit Message Format**:
```
<type>(<scope>): <subject>

<body>

<footer>
```

**Types**: `feat`, `fix`, `refactor`, `docs`, `test`, `chore`  
**Scopes**: Feature name (e.g., `BidIncrement`, `Notifications`)  
**Subject**: Imperative present tense, lowercase, no period  

### 9. Push & Create Pull Request

```bash
# Push to remote
git push origin feature/your-feature-name

# Create PR on GitHub
# - Link to related issues
# - Fill out PR template completely
# - Wait for code review
```

---

## Pull Request Checklist

**Before submitting:**

- [ ] **Tests**: All tests pass locally
  ```bash
  vendor/bin/phpunit
  ```

- [ ] **Coverage**: New code has 100% test coverage
  ```bash
  vendor/bin/phpunit --coverage-html coverage/
  ```

- [ ] **Code Quality**: All checks pass
  ```bash
  vendor/bin/phpstan analyse includes/ksfraser/
  vendor/bin/phpmd includes/ksfraser/ text cleancode
  vendor/bin/phpcs --standard=PSR2 includes/ksfraser/
  ```

- [ ] **Documentation**: PHPDoc blocks complete
  - Class docblock with `@requirement`
  - Method docblocks with parameters, returns, exceptions
  - Complex logic has inline comments

- [ ] **Architecture**: Follows principles
  - [ ] No modifications to original YITH method signatures
  - [ ] All new code in `ksfraser\` namespace
  - [ ] Single Responsibility Principle followed
  - [ ] Dependency injection used
  - [ ] No hardcoded values

- [ ] **Commit Message**: Conventional format followed

- [ ] **Related Issues**: Links to GitHub issues included

### PR Template

```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Feature
- [ ] Bug fix
- [ ] Refactor

## Related Issues
Fixes #123

## Changed Files
- includes/ksfraser/Feature/Class.php
- tests/Unit/ksfraser/Feature/ClassTest.php

## Testing
How to test these changes

## Coverage
- Line coverage: ??%
- New coverage: 100%
```

---

## Code Review Process

### For Reviewers

1. **Code Quality**: Does it follow standards?
2. **Tests**: Is coverage ≥ 100% for new code?
3. **Architecture**: Does it follow SRP, DI principles?
4. **Documentation**: Are PHPDoc blocks complete?
5. **Functionality**: Does it solve the stated problem?
6. **No Breaking Changes**: Does it maintain backward compatibility?

### For Contributors

- Respond to all comments
- Push follow-up commits for feedback
- Request re-review when ready

---

## Modifying Original YITH Code

**Policy**: Avoid modifying original YITH classes when possible.

### Before Modifying Original Code

1. **Can you use hooks/filters?**
   ```php
   // YES - Use Filter
   apply_filters('yith_wcact_starting_price', $price, $product_id)
   
   // Add to your ksfraser class:
   add_filter('yith_wcact_starting_price', [
       $this,
       'your_handler'
   ], 10, 2);
   ```

2. **Can you extend the class?**
   ```php
   // YES - Create extended class
   namespace ksfraser\Extended;
   
   class AuctionExtended extends \Yith\WCACT\Auction {
       public function your_new_method() {}
   }
   ```

3. **Must you modify the original?**
   If yes: Document with comment
   ```php
   // @modified-by ksfraser: Added hook for starting price customization
   // Original line preserved below
   // $starting_price = $product->get_price();
   $starting_price = apply_filters(
       'yith_wcact_starting_price',
       $product->get_price(),
       $product->get_id()
   );
   ```

---

## Minimum Standards

Any PR must meet these requirements:

| Check | Required | Status |
|-------|----------|--------|
| Tests exist | ✅ Yes | Pass locally |
| Tests pass | ✅ Yes | `vendor/bin/phpunit` |
| Coverage ≥ 100% (new code) | ✅ Yes | `phpunit --coverage-html` |
| PHPStan passes | ✅ Yes | `vendor/bin/phpstan` |
| PHPMD passes | ✅ Yes | `vendor/bin/phpmd` |
| PHPCS passes (PSR-2) | ✅ Yes | `vendor/bin/phpcs` |
| PHPDoc complete | ✅ Yes | All classes/methods |
| Requirement IDs present | ✅ Yes | `@requirement` tag |
| No YITH modifications | ✅ Unless documented | Design review required |
| ksfraser namespace used | ✅ Yes | All new code |

---

## Continuous Integration

All PRs automatically run:
- ✅ PHPUnit test suite
- ✅ Code coverage analysis
- ✅ PHPStan static analysis
- ✅ PHPMD mess detection
- ✅ PHPCS style check

**PR cannot merge if any check fails.**

---

## Issues & Bug Reports

### Find or Create Issues

- Check [existing issues](https://github.com/ksfraser/woocommerce-auction/issues)
- Create new issue with template:
  - Clear description
  - Steps to reproduce (bugs)
  - Expected vs actual behavior
  - PHP/WP/WC versions

### Issue Labels

- `bug` - Something isn't working
- `enhancement` - Feature request
- `documentation` - Docs improvement
- `good-first-issue` - Good for new contributors
- `help-wanted` - Need community help

---

## Documentation

### Update Docs For:
- New features
- API changes
- Architecture decisions
- Setup/configuration changes

### Documentation Files
- [README.md](README.md) - Overview & quick start
- [CODE_ORGANIZATION.md](CODE_ORGANIZATION.md) - Architecture & guidelines
- [TEST_COVERAGE_PLAN.md](TEST_COVERAGE_PLAN.md) - Testing strategy

---

## Questions?

- 💬 [Discussions](https://github.com/ksfraser/woocommerce-auction/discussions)
- 📖 [Documentation](README.md)
- 🐛 [Issues](https://github.com/ksfraser/woocommerce-auction/issues)

---

## Code of Conduct

Be respectful, inclusive, and constructive. We're building this together!

---

Thank you for contributing! 🚀
