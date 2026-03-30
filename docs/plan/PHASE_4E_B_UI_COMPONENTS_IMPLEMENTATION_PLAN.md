# Phase 4-E-B: UI Component Library Implementation Plan

**Status**: ACTIVE  
**Phase**: 4-E-B (Days 5-6)  
**Objective**: Build 15-20 reusable UI components for dashboard interface  
**Estimated LOC**: 1,200 (components) + 400 (tests)

---

## Overview

Phase 4-E-B establishes the foundation UI component library for admin and seller dashboards. Components follow WordPress/WooCommerce HTML generation patterns with direct instantiation (no builder chains), proper separation of concerns, and composite pattern architecture.

### Key Requirements
- **Direct Instantiation**: Components created with `new ComponentClass()`, not builder chains
- **Output Buffering**: All HTML generated as strings, output at once
- **Immutability**: Components produce consistent output (idempotent)
- **Composition**: Complex UI built from simple composable pieces
- **Type Safety**: Full type hints, property validation
- **Accessibility**: WCAG 2.1 compliance

---

## Architecture Pattern

```
Page/Dashboard
├── Layout Components
│   ├── Card (reusable container)
│   ├── Panel (bordered section)
│   ├── Grid/Row/Column (layout)
│   └── Modal (dialog box)
├── Data Components
│   ├── Table (data grid)
│   ├── List (item list)
│   └── Details (key-value pairs)
├── Form Components
│   ├── TextInput
│   ├── SelectBox
│   ├── DatePicker
│   ├── Button
│   └── Form (container)
├── Display Components
│   ├── Badge (status indicator)
│   ├── Alert (messages)
│   ├── Progress (progress bar)
│   ├── Chart (chart container)
│   └── Timeline (event timeline)
└── Structure
    ├── Render as: `$html = $component->render();`
    └── Output once: `echo $html;`
```

---

## Component Breakdown

### Layer 1: Base Components (4 components, 180 LOC)

#### 1. HtmlElement (Base Class)
```php
class HtmlElement {
  protected string $tag = 'div';
  protected array $attributes = [];
  protected array $children = [];
  protected string $content = '';
  
  public function render(): string { ... }
  public function addClass(string $class): self { ... }
  public function addAttribute(string $name, string $value): self { ... }
}
```
**Features**: Base rendering, attribute management, fluent interface for configuration

#### 2. Container
```php
class Container extends HtmlElement {
  public function addChild(HtmlElement $child): self { ... }
  public function render(): string { ... }
}
```
**Features**: Composable children, recursive rendering

#### 3. Grid/Layout System
```php
class Grid extends HtmlElement { ... }
class Row extends HtmlElement { ... }
class Column extends HtmlElement { ... }
```
**Features**: Bootstrap-style 12-column grid

#### 4. Wrapper/Panel
```php
class Panel extends Container {
  public function setTitle(string $title): self { ... }
  public function setFooter(HtmlElement $footer): self { ... }
}
```

### Layer 2: Dashboard Components (6 components, 420 LOC)

#### 5. Card
```php
class DashboardCard extends Container {
  public function setHeader(string $title, ?string $meta = null): self { ... }
  public function setBody(string|HtmlElement $content): self { ... }
  public function setFooter(?HtmlElement $footer = null): self { ... }
}
```

#### 6. StatusBadge
```php
class StatusBadge extends HtmlElement {
  public function __construct(string $status, string $label) { ... }
}
// Usage: new StatusBadge('completed', 'Completed')
// Output: <span class="badge badge-completed">Completed</span>
```

#### 7. MetricsDisplay
```php
class MetricsDisplay extends Container {
  public function addMetric(string $label, $value): self { ... }
}
```

#### 8. DataTable
```php
class DataTable extends HtmlElement {
  public function setHeaders(array $headers): self { ... }
  public function addRow(array $cells): self { ... }
  public function render(): string { ... }
}
```

#### 9. FilterPanel
```php
class FilterPanel extends Container {
  public function addFilter(string $name, string $type): self { ... }
  public function render(): string { ... }
}
```

#### 10. TimelineView
```php
class TimelineView extends Container {
  public function addEvent(string $title, string $date, $details = null): self { ... }
}
```

### Layer 3: Form Components (5 components, 300 LOC)

#### 11. FormElement (Base)
```php
abstract class FormElement extends HtmlElement {
  protected string $name;
  protected string $label;
  protected ?string $value = null;
  protected bool $required = false;
  
  public function setLabel(string $label): self { ... }
  public function setValue($value): self { ... }
  public function setRequired(bool $required): self { ... }
}
```

#### 12. TextInput
```php
class TextInput extends FormElement {
  public function __construct(string $name, string $type = 'text') { ... }
  public function render(): string { ... }
}
```

#### 13. SelectBox
```php
class SelectBox extends FormElement {
  public function addOption(string $value, string $label): self { ... }
  public function render(): string { ... }
}
```

#### 14. DatePicker
```php
class DatePicker extends FormElement {
  public function render(): string { ... }
}
```

#### 15. Form
```php
class Form extends Container {
  public function setMethod(string $method): self { ... }
  public function setAction(string $action): self { ... }
  public function render(): string { ... }
}
```

### Layer 4: Display Components (5 components, 300 LOC)

#### 16. Alert
```php
class Alert extends HtmlElement {
  public function __construct(string $message, string $type = 'info') { ... }
  // Types: info, success, warning, error
}
```

#### 17. ProgressBar
```php
class ProgressBar extends HtmlElement {
  public function __construct(int $percentage, ?string $label = null) { ... }
}
```

#### 18. Button
```php
class Button extends HtmlElement {
  public function __construct(string $text, string $action = 'button') { ... }
  public function setClass(string $class): self { ... }
}
```

#### 19. Badge
```php
class Badge extends HtmlElement {
  public function __construct(string $text, string $variant = 'default') { ... }
}
```

#### 20. Spinner/Loader
```php
class Spinner extends HtmlElement {
  public function render(): string { ... }
}
```

---

## Implementation Strategy

### Phase 4-E-B-1: Base Components (250 LOC)
1. Create `HtmlElement` abstract base class
2. Create `Container` for composition
3. Create `Grid`, `Row`, `Column` layout system
4. Create `Panel` wrapper component
5. Create base form element class

**Tests**: 4 integration tests (rendering, attributes, composition)

### Phase 4-E-B-2: Dashboard Components (320 LOC)
1. Create `DashboardCard`
2. Create `StatusBadge`
3. Create `MetricsDisplay`
4. Create `DataTable`
5. Create `FilterPanel`
6. Create `TimelineView`

**Tests**: 6 integration tests (card rendering, table rows, filter UI)

### Phase 4-E-B-3: Form Components (250 LOC)
1. Create `FormElement` base class
2. Create `TextInput`
3. Create `SelectBox`
4. Create `DatePicker`
5. Create `Form` container

**Tests**: 5 integration tests (form rendering, input types, validation)

### Phase 4-E-B-4: Display Components (240 LOC)
1. Create `Alert`
2. Create `ProgressBar`
3. Create `Button`
4. Create `Badge`
5. Create `Spinner`

**Tests**: 4 integration tests (alert types, progress rendering, button states)

### Phase 4-E-B-5: Integration & Composition (140 LOC)
1. Create `DashboardLayout` (composes all components)
2. Create `PageBuilder` (higher-level abstraction)
3. Helper functions for common patterns

**Tests**: 5 integration tests (full page rendering, nested composition)

---

## File Structure

```
includes/
  components/
    Base/
      HtmlElement.php (80 LOC)
      Container.php (60 LOC)
    Layout/
      Grid.php (40 LOC)
      Row.php (30 LOC)
      Column.php (30 LOC)
      Panel.php (50 LOC)
    Dashboard/
      DashboardCard.php (70 LOC)
      StatusBadge.php (40 LOC)
      MetricsDisplay.php (60 LOC)
      DataTable.php (90 LOC)
      FilterPanel.php (80 LOC)
      TimelineView.php (80 LOC)
    Form/
      FormElement.php (60 LOC)
      TextInput.php (50 LOC)
      SelectBox.php (60 LOC)
      DatePicker.php (50 LOC)
      Form.php (70 LOC)
    Display/
      Alert.php (50 LOC)
      ProgressBar.php (50 LOC)
      Button.php (50 LOC)
      Badge.php (40 LOC)
      Spinner.php (40 LOC)
    Builders/
      DashboardLayout.php (80 LOC)
      PageBuilder.php (60 LOC)

tests/
  integration/
    ComponentsBase/
      HtmlElementIntegrationTest.php
      ContainerIntegrationTest.php
      GridLayoutIntegrationTest.php
    ComponentsDashboard/
      DashboardCardIntegrationTest.php
      DataTableIntegrationTest.php
    ComponentsForm/
      FormComponentsIntegrationTest.php
    ComponentsDisplay/
      DisplayComponentsIntegrationTest.php
    ComponentsComposition/
      DashboardLayoutIntegrationTest.php
```

---

## Testing Strategy

### Unit Tests
- Component initialization
- Property accessors/mutators
- Attribute management
- CSS class application

### Integration Tests
- HTML rendering output
- Attribute escaping
- Nested composition
- WordPress escaping functions

### Component Test Coverage

**Base Components** (4 tests)
1. HtmlElement renders with correct tag/attributes
2. Container renders children recursively
3. Grid layout generates proper structure
4. Panel includes title/footer correctly

**Dashboard Components** (6 tests)
1. Card renders header/body/footer
2. StatusBadge applies correct CSS class
3. MetricsDisplay formats display values
4. DataTable generates table HTML with headers/rows
5. FilterPanel renders with filter inputs
6. TimelineView events render in order

**Form Components** (5 tests)
1. Form container generates `<form>` tag
2. TextInput renders with label
3. SelectBox renders with options
4. DatePicker outputs date input
5. Form submission attributes set correctly

**Display Components** (4 tests)
1. Alert renders with type class
2. ProgressBar shows percentage
3. Button renders with action class
4. Badge renders with variant styling

**Composition Tests** (5 tests)
1. DashboardLayout composes multiple components
2. Nested containers render correctly
3. Complex form with multiple fields
4. Page with cards + tables + filters
5. Multiple alerts in container

**Total Test Cases**: 24+ integration tests

---

## Success Criteria

- ✅ All 20 components created and functional
- ✅ 1,200 LOC production code
- ✅ 24+ integration tests passing
- ✅ 90%+ code coverage
- ✅ All components follow AGENTS.md patterns
- ✅ No external builder dependencies
- ✅ Full PHPDoc documentation
- ✅ WCAG 2.1 accessibility compliance
- ✅ PSR-12 compliant code
- ✅ 100% type hints

---

## Deliverables

### Code Files (1,200 LOC)
1. 20 component classes
2. Base classes and traits
3. Helper functions
4. Component factories

### Test Files (400+ LOC)
1. 24+ integration tests
2. Component rendering assertions
3. Composition tests
4. Accessibility tests

### Documentation (200 LOC)
1. Component usage guides
2. Composition examples
3. API documentation
4. Accessibility notes

---

## Timeline

**Phase 4-E-B-1**: Day 5, Morning (Base Components)
- Create HtmlElement, Container, Grid system
- 1 test suite

**Phase 4-E-B-2**: Day 5, Afternoon (Dashboard Components)
- Create Card, Badge, MetricsDisplay, DataTable, FilterPanel, Timeline
- 2 test suites

**Phase 4-E-B-3**: Day 5, Evening (Form Components)
- Create Form, TextInput, SelectBox, DatePicker
- 2 test suites

**Phase 4-E-B-4**: Day 6, Morning (Display Components)
- Create Alert, Progress, Button, Badge, Spinner
- 2 test suites

**Phase 4-E-B-5**: Day 6, Afternoon (Composition + Testing)
- Create Layout builders
- 5 composition tests
- Final review and commit

---

## Next Phase Dependency

Phase 4-E-C (Seller Dashboard) will use these components to build:
- Payout list dashboard
- Batch monitoring dashboard
- Account settings page
- Report generation page

---

## Architecture Diagram

```
Using Components:
  Page/View
    └── PageBuilder
        └── Layout
            ├── Container
            │   ├── DashboardCard
            │   │   ├── StatusBadge
            │   │   ├── DataTable
            │   │   │   ├── Row [cells]
            │   │   │   └── Row [cells]
            │   │   └── Button
            │   ├── FilterPanel
            │   │   ├── TextInput
            │   │   └── SelectBox
            │   └── MetricsDisplay
            ├── Alert
            └── Form
                ├── TextInput
                ├── SelectBox
                └── Button

Rendering Flow:
  1. Create components: $card = new DashboardCard();
  2. Configure: $card->setHeader('Title')->addChild($table);
  3. Render: $html = $card->render();
  4. Output: echo $html;
```

---

## Dependencies

- **Phase 4-D Services**: Data providers (PayoutService, etc.)
- **Phase 4-E-A Services**: Dashboard services (PayoutDashboardService, etc.)
- **WordPress API**: Escaping functions (esc_html, esc_attr)
- **CSS Framework**: Bootstrap or custom classes
- **No External UI Libraries**: All HTML generation in PHP

---

**Status**: Ready for implementation 🚀

Next: Begin Phase 4-E-B-1 with base component creation
