# Contributing to WooCommerce Auction

Thank you for considering contributing to WooCommerce Auction! This document provides guidelines and instructions for contributing to the project.

## Code of Conduct

Please be respectful and professional in all interactions. We're committed to providing a welcoming environment for all contributors.

## Getting Started

### Prerequisites
- PHP 7.3+
- Git
- Composer 2.0+
- Docker (recommended for consistent development environment)
- WordPress 5.0+ (for testing)
- WooCommerce 3.0+

### Development Setup

#### Option 1: Docker (Recommended)
```bash
git clone https://github.com/ksfraser/yith-auctions-for-woocommerce.git
cd yith-auctions-for-woocommerce
cp .env.example .env
docker-compose up -d
```
See [DOCKER-SETUP.md](DOCKER-SETUP.md) for detailed instructions.

#### Option 2: Local Development
```bash
# Clone the repository
git clone https://github.com/ksfraser/woocommerce-auction.git
cd woocommerce-auction

# Install dependencies
composer install

# Run tests
./vendor/bin/phpunit

# Code analysis
./vendor/bin/phpstan analyse
./vendor/bin/phpmd includes xml .phpmd.xml
./vendor/bin/phpcs includes --standard=phpcs.xml.dist
```

## Branching Strategy

- **main**: Production-ready code
- **develop**: Development branch (default PR target)
- **feature/**: Feature branches (e.g., `feature/auto-bidding`)
- **bugfix/**: Bug fix branches (e.g., `bugfix/bid-validation`)
- **docs/**: Documentation updates

### Creating a Feature Branch
```bash
git checkout develop
git pull origin develop
git checkout -b feature/your-feature-name
```

## Development Workflow

### 1. Create Your Feature Branch
```bash
git checkout -b feature/your-feature-name
```

### 2. Make Your Changes
- Follow [Code Style Guide](#code-style-guide)
- Write tests for new functionality
- Update documentation

### 3. Run Code Quality Checks
```bash
# Run all checks
./vendor/bin/phpstan analyse
./vendor/bin/phpmd includes xml .phpmd.xml
./vendor/bin/phpcs includes --standard=phpcs.xml.dist
./vendor/bin/phpunit tests/

# Or use Docker
docker-compose exec wordpress composer test
```

### 4. Commit Your Changes
```bash
git add .
git commit -m "feat: Add new feature description"
```

Use conventional commit messages:
- `feat:` New feature
- `fix:` Bug fix
- `docs:` Documentation
- `style:` Code style changes
- `refactor:` Code refactoring
- `test:` Adding tests
- `chore:` Build, dependencies, etc.

### 5. Push and Create Pull Request
```bash
git push origin feature/your-feature-name
```

Then create a pull request on GitHub.

## Code Style Guide

### General Principles
- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards
- Follow [WordPress Coding Standards](https://developer.wordpress.org/plugins/php/wordpress-coding-standards/)
- Use SOLID design principles
- Prefer composition over inheritance
- Write self-documenting code

### Naming Conventions
```php
// Classes: PascalCase
class AuctionProduct { }
class BidValidator { }

// Methods/Functions: camelCase
public function getBidAmount() { }
private function validateBid() { }

// Constants: UPPER_SNAKE_CASE
const MAX_AUCTION_DURATION = 30;
const MINIMUM_BID_INCREMENT = 0.50;

// Variables: snake_case or camelCase
$auction_id = 123;
$currentBid = 99.99;
```

### PHPDoc Standards
```php
/**
 * Brief description of what the class/method does
 *
 * Longer description if needed. Explain the purpose, behavior, and any
 * important details about the implementation.
 *
 * @requirement REQ-123 Related requirement ID
 *
 * @param  string $auction_id    The auction identifier
 * @param  array  $bid_data      Bid information (see structure below)
 *                               - 'amount': Bid amount (float)
 *                               - 'user_id': Bidder user ID (int)
 * @param  bool   $increment_bid Optional. Whether to auto-increment. Default false.
 *
 * @return bool True if bid was placed successfully, false otherwise
 *
 * @throws InvalidBidException If bid amount is invalid
 * @throws AuctionExpiredException If auction has ended
 *
 * @since 1.0.0
 * @see   AuctionBidValidator::validate()
 * @see   AuctionRepository::saveBid()
 */
public function placeBid( $auction_id, array $bid_data, $increment_bid = false ) { }
```

### Type Hints
```php
// Always use type hints for parameters and return types
public function getBid( int $bid_id ): Bid {
    return $this->repository->find( $bid_id );
}

// Use union types for PHP 8+
public function limitBid( int|float $amount ): int|float {
    return min( $amount, $this->max_bid );
}

// Use null type for optional returns
public function findAuction( int $auction_id ): ?Auction {
    return $this->repository->find( $auction_id );
}
```

### Formatting
Use PHPCS to auto-fix issues:
```bash
./vendor/bin/phpcs --standard=phpcs.xml.dist includes/ --fix
```

## Testing Requirements

All code contributions must include tests.

### Test Types

#### Unit Tests
Test individual components in isolation:
```php
class BidValidatorTest extends TestCase {
    public function test_validates_minimum_bid_amount() {
        $validator = new BidValidator();
        $result = $validator->validate( [ 'amount' => 9.99 ] );
        $this->assertTrue( $result );
    }
}
```

#### Integration Tests
Test component interactions:
```php
class AuctionWorkflowTest extends TestCase {
    public function test_complete_auction_workflow() {
        // Create auction -> Place bids -> Determine winner
    }
}
```

### Running Tests
```bash
# All tests
./vendor/bin/phpunit

# Specific test file
./vendor/bin/phpunit tests/unit/BidValidatorTest.php

# With coverage
./vendor/bin/phpunit --coverage-html=coverage

# In Docker
docker-compose exec wordpress ./vendor/bin/phpunit
```

### Coverage Requirements
- Minimum 80% code coverage
- 100% coverage for critical paths (bid placement, winner determination)
- Focus on branch coverage, not just line coverage

## Documentation

### Code Documentation
- Add PHPDoc comments to all classes, methods, and properties
- Include `@requirement` tags linking to specification
- Include usage examples for complex functionality

### README Updates
Update `README.md` if your changes affect:
- Installation process
- Usage instructions
- Configuration options
- Database schema

### CHANGELOG
Add entries to `CHANGELOG.md` for user-facing changes:
```markdown
## [1.4.0] - 2024-01-15
### Added
- Auto-bidding feature with proxy bid support
- New bid increment tiers configuration

### Fixed
- Issue with concurrent bid race conditions
```

## Pull Request Guidelines

### Before Submitting
1. ✅ All tests pass
2. ✅ Code coverage ≥ 80%
3. ✅ PHPCS standards compliance
4. ✅ PHPStan analysis passes at level 5
5. ✅ PHPDoc comments complete
6. ✅ Commit messages follow conventions

### PR Description Template
```markdown
## Description
Brief description of changes

## Type
- [ ] Feature
- [ ] Bug Fix
- [ ] Documentation
- [ ] Performance

## Related Issue
Closes #123

## Testing
Describe how to test the changes

## Checklist
- [ ] Tests written and passing
- [ ] Code follows style guide
- [ ] Documentation updated
- [ ] No breaking changes
```

### Merge Criteria
- ✅ All CI/CD checks pass
- ✅ At least one code review approval
- ✅ Tests coverage ≥ 80%
- ✅ No conflicts with target branch

## Bug Reports

When reporting bugs, please include:

1. **Environment**
   - PHP version
   - WordPress version
   - WooCommerce version
   - Plugin version

2. **Steps to Reproduce**
   - Clear, numbered steps
   - Expected behavior
   - Actual behavior

3. **Screenshots/Logs**
   - Error messages
   - Debug logs
   - Screenshots if applicable

## Feature Requests

When suggesting features:

1. **Description**
   - Clear, concise description
   - Why it's needed
   - Use cases

2. **Examples**
   - How it would be used
   - Configuration options
   - Integration points

3. **Acceptance Criteria**
   - What success looks like
   - Test scenarios

## Code Review Process

1. **Submit PR** with description and tests
2. **Automated Checks** run (CI/CD pipeline)
3. **Manual Review** by maintainers
4. **Address Feedback** through additional commits
5. **Approval** and merge to target branch

## Performance Considerations

When writing code, consider:

- **Database Queries**: Use indices, avoid N+1 problems
- **Caching**: Cache frequently accessed data
- **Memory Usage**: Avoid loading entire datasets
- **File I/O**: Stream large files, batch operations
- **Network Calls**: Use timeouts, implement retries

## Security Considerations

- **Input Validation**: Validate all user input
- **SQL Injection**: Use prepared statements
- **XSS Protection**: Escape output appropriately
- **CSRF Protection**: Use nonces for form submissions
- **Authorization**: Check user permissions
- **Data Privacy**: Follow GDPR and data protection laws

## Resources

- [PHP Standards](https://www.php-fig.org/)
- [WordPress Plugin Development](https://developer.wordpress.org/plugins/)
- [WooCommerce Documentation](https://docs.woocommerce.com/)
- [SOLID Principles](https://en.wikipedia.org/wiki/SOLID)
- [PHPDoc Standards](https://docs.phpdoc.org/)

## Getting Help

- 📚 Check existing documentation
- 🔍 Search GitHub issues
- 💬 Ask in GitHub discussions
- 📧 Contact maintainers

## Community

We appreciate your contributions! Contributors will be:
- Added to CONTRIBUTORS file
- Recognized in release notes
- Invited to discuss major decisions

Thank you for helping make WooCommerce Auction better! 🎉
