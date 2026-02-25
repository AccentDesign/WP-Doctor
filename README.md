# WP Doctor

A smart WordPress code health analyzer. Scans custom plugins and themes for security issues, PHP 8 compatibility, performance problems, and more.

## Features

- **Smart Plugin Detection** - Auto-detects WordPress.org plugins vs custom code
- **Security Analysis** - SQL injection, missing nonces, capability checks, XSS
- **PHP 8 Compatibility** - Null safety issues, deprecated functions
- **Performance Audits** - N+1 queries, uncached remote requests
- **Health Score** - 0-100 score for quick assessment
- **Claude Code Integration** - Auto-configures permissions for AI-assisted fixing

## Installation

### Standalone (Recommended)

```bash
git clone https://github.com/accentdesign/wp-doctor.git
cd wp-doctor
composer install
```

### Via Composer

```bash
composer require accentdesign/wp-doctor
```

## Usage

### Basic Scan

```bash
# Scan wp-content directory
php bin/wp-doctor scan /path/to/wordpress/wp-content

# Non-interactive mode (for CI/scripts)
php bin/wp-doctor scan /path/to/wp-content --non-interactive

# JSON output for automation
php bin/wp-doctor scan /path/to/wp-content --format=json
```

### Plugin Classification

WP Doctor automatically detects plugins from WordPress.org and skips them. For custom plugins, it will prompt you to classify them:

```bash
# Detect unclassified plugins (outputs JSON)
php bin/wp-doctor detect /path/to/wp-content

# Set plugin classification
php bin/wp-doctor set-plugin /path/to/wp-content my-plugin custom
php bin/wp-doctor set-plugin /path/to/wp-content some-lib third-party

# Set active theme
php bin/wp-doctor set-theme /path/to/wp-content theme-name
```

### Scan Modes

```bash
# Default: Custom plugins + active theme only
php bin/wp-doctor scan /path/to/wp-content

# Scan ALL plugins (including third-party)
php bin/wp-doctor scan /path/to/wp-content --all
```

## Health Score

WP Doctor calculates a health score from 0-100:

| Score | Rating | Description |
|-------|--------|-------------|
| 90-100 | Great | Minor or no issues |
| 70-89 | Good | Some issues to address |
| 50-69 | Fair | Needs attention |
| 0-49 | Poor | Significant problems |

**Scoring Formula:**
- Each error rule type: -1.5 points
- Each warning rule type: -0.75 points

## What It Checks

### Security
- SQL injection vulnerabilities
- Missing nonce verification
- Missing capability checks on AJAX handlers
- Debug code in production

### PHP 8 Compatibility
- Null passed to count(), strlen(), etc.
- Deprecated functions
- Type safety issues

### Performance
- N+1 query patterns
- Uncached remote requests
- Inefficient database queries

### Code Quality
- WordPress coding standards
- Hook usage problems
- Dead code detection

## Configuration

WP Doctor stores configuration in `.wp-doctor/` in your project root:

- `plugins.json` - Plugin classifications and active theme

On first run, it also creates:
- `.claude/settings.local.json` - Claude Code permissions
- `.gitignore` entry for `.wp-doctor/`

## Claude Code Integration

WP Doctor is designed to work seamlessly with Claude Code:

1. Run `wp-doctor scan` to identify issues
2. Claude reads the JSON output
3. Claude fixes the code
4. Re-run scan to verify improvements

The auto-generated Claude settings allow:
- Running wp-doctor commands
- PHP syntax checking
- Git operations
- Common development tools

## CI/CD Integration

```bash
# Fail if score below 80
score=$(php bin/wp-doctor scan /path/to/wp-content --format=json --non-interactive | jq '.score.score')
if [ "$score" -lt 80 ]; then
  echo "Health score $score is below threshold"
  exit 1
fi
```

## Safety

WP Doctor is completely safe:

- **Read-only** - Scanning never modifies any files
- **No database access** - Never touches the database
- **No remote calls** - Works entirely offline
- **Skips vendored code** - Ignores vendor/, node_modules/, etc.

## Requirements

- PHP 7.4 or higher
- Composer (for installation)

## License

MIT - see [LICENSE](LICENSE)

## Credits

Built by [Accent Design](https://accentdesign.co.uk)
