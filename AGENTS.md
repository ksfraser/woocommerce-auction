# Technical Requirements

## Souce Control
- **Git**: Files will be stored in git
- **github**: repos will be stored in our github account.
- **gitignore**: vendor directory (from composer) will ALWAYS be excluded from git.

## Platform and Environment

- **Target Platform**: Compatible with major PHP-based business applications
- **PHP Version**: 7.3+
- **Database**: MySQL/PostgreSQL compatible databases
- **Web Server**: Apache/Nginx compatible environments

## Module Architecture

### Core Module Design
Generic module infrastructure providing:

- **Extension Points**: Hook system allowing plugins to extend functionality
- **Generic Services**: Base service classes for common operations
- **Data Access Layer**: Standardized DAO patterns
- **Configuration Management**: Environment-specific settings

### Plugin System
Modules support plugins that add specific functionality:

- **Plugin Architecture**: Clean separation between core and plugin functionality
- **Dependency Management**: Plugins depend on core module, not vice versa
- **Extension Registration**: Plugins register extensions to core hook points
- **Version Compatibility**: Plugin versioning and compatibility checks

### Database Design Principles
Core module manages foundational data structure:

- **Normalized Schema**: Proper normalization for data integrity
- **Indexing Strategy**: Performance-optimized indexing
- **Migration Support**: Versioned database migrations
- **Audit Trails**: Change tracking and audit logging

Plugins can extend with additional tables following established patterns.

## Development Principles

### SOLID Principles
- **Single Responsibility Principle (SRP)**: Each class has one reason to change
- **Open/Closed Principle**: Classes open for extension, closed for modification
- **Liskov Substitution Principle**: Subtypes are substitutable for their base types
- **Interface Segregation Principle**: Clients not forced to depend on methods they don't use
- **Dependency Inversion Principle**: Depend on abstractions, not concretions

### Design Patterns and Practices
- **Dependency Injection (DI)**: Use constructor injection for dependencies
- **Polymorphism over Conditionals**: Use SRP classes and polymorphism instead of conditional logic where possible (following Martin Fowler's "Replace Conditional with Polymorphism"). **THERE SHOULD BE FEW IF/THEN/ELSE BLOCKS NOR SWITCH STATEMENTS**. Instead use classes (per Fowler) where it returns the output the if'd function would have, or return nothing (NULL).
- **DRY (Don't Repeat Yourself)**: Use parent classes, traits, and composition
- **Composition over Inheritance**: Prefer composition where appropriate
- **Strategy Pattern**: For interchangeable algorithms
- **Factory Pattern**: For object creation
- **Observer Pattern**: For event-driven architecture
- **Security by Design**: Sanitize all inputs, use prepared statements, and follow least privilege principles.
- **Logging & Monitoring**: Implement structured logging with configurable levels (error, warning, info, debug). Logging level should be settable via config file or MySQL DB table.

## Code Quality

### Documentation
- **Project Documentation**: Standardized documentation in `Project Docs/` directory based on BABOK (Business Analysis Body of Knowledge) outputs
    - Expected documents: Business Requirements, Functional Requirements, Use Cases, Architecture, Design docs, Test Cases, RTM, BRD (Business Requirements Document), FRD (Functional Requirements Document), NFR (Non-Functional Requirements), ERD (Entity Relationship Diagram), UML Class Diagrams, Message Flow Diagrams, Program Flow Diagrams, QA Test Plan, and UAT (User Acceptance Test) checklist.
- **Automated Documentation**: Use phpDocumentor to generate HTML documentation.
    - **UML in PHPDoc**: All classes must include UML diagrams in their PHPDoc blocks describing structure, message flows, and variable passing.
    - **Relationship Diagrams**: Include class relationship diagrams (inheritance, composition, dependencies).
    - **Automation**: Use a `generate-docs.sh` (or equivalent) script to automate the generation of documentation artifacts (including JPG/PNG diagrams).
- **PHPDoc**: Comprehensive PHPDoc blocks for all classes, methods, and properties
- **Inline Comments**: Clear comments for complex logic
- **README**: Detailed usage instructions and API documentation
- **Architecture Documentation**: System design and component relationships
    - **Dual Approach**: Maintain both high-level architecture documents and implementation-specific docs mapping classes/methods to components.
    - **Traceability**: Update docs whenever new classes/functions are added to ensure message flow and interaction mapping.

### UI Framework Standards
- **HTML Generation Library**: Use established HTML generation libraries
- **Direct Instantiation Pattern**: HTML elements created with `new HtmlElement()` instead of builder chains
- **Output Buffering**: No immediate echo output; all HTML generated as strings and output at once
- **Reusable Components**: Table classes, Button classes, Form classes
- **Composite Pattern**: Page objects containing components with recursive display() calls
- **SRP UI Components**: Complex UI sections extracted into dedicated classes implementing library interfaces
- **Consistent UI**: All forms, tables, and UI elements generated through the library
- **Separation of Concerns**: UI generation separated from business logic

### Testing Standards
- **Test-Driven Development (TDD)**: Write tests before implementing functionality (Red-Green-Refactor cycle).
    - **Requirement Mapping**: All unit tests must map back to specific requirements in the traceability matrix.
    - **TDD Workflow**: Write the unit test first (it should fail), then implement the code to make it pass.
    - **Traceability**: Each code unit should indicate (in comments or docblocks) which requirement it fulfills.
- **Unit Tests**: 100% code coverage for all classes and methods
    - **Requirement Mapping**: All unit tests must map back to specific requirements in the traceability matrix.
- **Edge Cases**: Test all boundary conditions, error scenarios, and invalid inputs
- **Mocking**: Use mocks/stubs for external dependencies (database, file system, etc.). Use dependency injection to facilitate mocking and testing.
- **Test Frameworks**: PHPUnit for unit testing
- **Test Structure**: Tests in `tests/` directory with PHPUnit configuration
- **Coverage Reports**: HTML and text coverage reports generated automatically
- **Integration Tests**: End-to-end testing of component interactions

### Interfaces and Contracts
- **Interfaces**: Define contracts for key components (Validators, Processors, etc.)
- **Abstract Classes**: Provide common implementations where appropriate
- **Traits**: Extract reusable functionality to avoid duplication
- **Type Hints**: Strict typing for method parameters and return values
- **Exception Hierarchy**: Custom exceptions for different error types
    - Define and use custom exception classes for different error conditions instead of relying solely on generic exceptions.
    - Use exception hierarchies to represent different error types and enable precise error handling.

## Architecture

### Layered Architecture Pattern
- **Presentation Layer**: UI components and controllers. Generate UI/View code as classes with `render()` functions. Keep rendering logic separate from business logic.
- **Business Logic Layer**: Domain services and validation. Place business rules in service classes, not in controllers or views.
- **Data Access Layer**: DAO classes with standardized patterns.
    - **Traceability Matrix**: Maintain a matrix mapping requirements to implementation (class/function/file). Update as code evolves.
- **Infrastructure Layer**: External services (logging, file handling, etc.).

### Key Architectural Components
- **Core Services**: Generic service classes for common business operations
- **Domain Services**: Business logic specific to the domain
- **Validators**: Separate validation classes for different data types
- **Hook System**: Cross-module integration and extension points
- **Utility Classes**: Common functionality and helpers
- **Exception Hierarchy**: Structured error handling

### Extension Points Design
- **Hook Registration**: Modules register extensions to core hook points
- **Plugin Loading**: Dynamic plugin discovery and loading
- **Event System**: Publish-subscribe pattern for loose coupling
- **Configuration Extensions**: Plugin-specific configuration options
- **URL Parameter Namespacing**: Module-specific query parameters to prevent cross-contamination (e.g., `product_attributes_subtab` instead of generic `subtab`)

## Quality Assurance

### Code Review Checklist
- SOLID principles compliance
- PHPDoc completeness
- Test coverage verification (100%)
- Security considerations
- Performance implications
- Design pattern usage

### Continuous Integration
- Automated testing on commits
- Code quality checks (PHPStan, PHPMD)
- Dependency vulnerability scanning
- Documentation generation
- Static analysis tools

## Security Requirements

- Input validation and sanitization
- SQL injection prevention (parameterized queries)
- XSS protection in HTML output
- CSRF protection for forms
- Access control integration
- Audit logging for all operations
- Data integrity checks
- Secure configuration management- **Structured Logging**: Implement structured logging with configurable levels (error, warning, info, debug) settable via config file or database.
## Performance Requirements

- Efficient database queries with proper indexing
- Memory-efficient processing of large datasets
- Transaction management for data consistency
- Caching strategies for repeated operations
- Lazy loading for large object graphs
- Query optimization and N+1 problem prevention
- Resource cleanup and memory management

## Implementation Guidelines
.
- **Requirement References**: ALL code generated must reference the requirement it fulfills (ID/ref) in PHPDoc and comments.
    - **Requirement IDs**: Include requirement ID/reference in phpdoc blocks using custom tags like `@requirement REQ-001` or `@covers-requirement REQ-001`.
    - **Traceability**: Ensure all classes and methods can be traced back to specific requirements in the traceability matrix.
- **Code Reviews**: Peer review for all changes.
- **Branching Strategy**: Feature branches with pull requests.
- **Version Control**: Semantic versioning and changelog maintenance.
- **Code Organization**: 
    - **Class Design**: One class = one responsibility. Limit the number of methods and properties.
    - **Function Design**: Functions should do one thing and do it well. Prefer pure functions where possible.
    - **Reuse**: Extract reusable components into Composer packages.
    - **Legacy Migration**: When refactoring/migrating, do not delete old code until the new implementation passes all unit tests. Leave a comment in the old location pointing to the new one.
- **Branching Strategy**: Feature branches with pull requests
- **Version Control**: Semantic versioning and changelog maintenance

### Module Structure Standards
- **PSR-4 Autoloading**: Standard PHP autoloading
- **Namespace Organization**: Logical grouping by functionality
- **File Organization**: Consistent directory structure
- **Dependency Management**: Composer for PHP dependencies
    - **Third-Party Packages**: Research and evaluate existing packages before implementing custom solutions. Prefer well-maintained and tested plugins.

### Error Handling
- **Exception Hierarchy**: Custom exceptions for different error types
- **Error Logging**: Comprehensive logging with appropriate levels
- **User-Friendly Messages**: Clear error messages for end users
- **Graceful Degradation**: System continues operating during failures

### Configuration Management
- **Environment-Specific Configs**: Different settings for dev/staging/production
- **Configuration Validation**: Validate configuration on startup
- **Runtime Configuration**: Allow dynamic configuration changes
- **Security**: Protect sensitive configuration data

## Testing Strategy

### Unit Testing
- **Test Isolation**: Each test independent and repeatable
- **Mock External Dependencies**: Database, file system, network calls
- **Test Data Builders**: Fluent builders for complex test data
- **Assertion Libraries**: Rich assertions for complex validations

### Integration Testing
- **Database Integration**: Test actual database operations
- **API Integration**: Test external service interactions
- **End-to-end Scenarios**: Complete user workflows
- **Performance Testing**: Load and stress testing

### Test Organization
- **Test Suites**: Organized by functionality and layer
- **Test Fixtures**: Reusable test data and setup
- **Test Utilities**: Helper classes for common test operations
- **Continuous Testing**: Tests run on every commit

## Documentation Standards

### Code Documentation
- **PHPDoc Standards**: Consistent format and completeness
- **API Documentation**: Generated from PHPDoc comments
- **Architecture Diagrams**: UML diagrams for system design
- **Sequence Diagrams**: Message flow for complex operations

### User Documentation
- **Installation Guide**: Step-by-step setup instructions
- **Configuration Guide**: All configuration options explained
- **User Manual**: Feature usage and workflows
- **Troubleshooting Guide**: Common issues and solutions

### Developer Documentation
- **Architecture Guide**: System design and component relationships
- **API Reference**: Complete API documentation
- **Extension Guide**: How to extend and customize the system
- **Contributing Guide**: Development workflow and standards

## Deployment and Operations

### Packaging
- **Composer Packages**: Standard PHP packaging
- **Version Constraints**: Compatible version ranges
- **Dependency Resolution**: Automatic dependency management
- **Installation Scripts**: Automated setup and configuration

### Environment Management
- **Development Environment**: Local development setup
- **Staging Environment**: Pre-production testing
- **Production Environment**: Live system configuration
- **Environment Parity**: Consistent environments across stages

### Monitoring and Maintenance
- **Logging Standards**: Structured logging with correlation IDs
- **Health Checks**: System health monitoring endpoints
- **Metrics Collection**: Performance and usage metrics
- **Backup Strategies**: Data backup and recovery procedures

## Compliance and Standards

### Coding Standards
- **PSR Standards**: PSR-1, PSR-2, PSR-4, PSR-12 compliance
- **PHP Standards**: Current PHP best practices
- **Security Standards**: OWASP guidelines and PHP security best practices
- **Accessibility Standards**: WCAG 2.1 compliance for web interfaces

### Data Standards
- **Data Validation**: Comprehensive input validation
- **Data Sanitization**: XSS and injection protection
- **Data Privacy**: GDPR and privacy regulation compliance
- **Data Retention**: Appropriate data lifecycle management