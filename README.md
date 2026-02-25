# WP Doctor

A smart WordPress code health analyzer. Scans custom plugins and themes for security issues, PHP 8 compatibility, performance problems, and more.

## Features

- **Smart Plugin Detection** - Auto-detects WordPress.org plugins vs custom code
- **Security Analysis** - SQL injection, missing nonces, capability checks, XSS
- **PHP 8 Compatibility** - Null safety issues, deprecated functions
- **Performance Audits** - N+1 queries, uncached remote requests
- **Health Score** - 0-100 score for quick assessment
- **Claude Code Integration** - Auto-configures permissions for AI-assisted fixing

## Quick Start

```bash
git clone https://github.com/accentdesign/wp-doctor.git
cd wp-doctor
./wp-doctor-setup
```

This checks prerequisites (PHP, Node.js, Composer), installs dependencies, and creates the MCP config.

Then start Claude:

```bash
claude
```

Say anything (like "go" or "hi") and Claude will:
1. Ask for your WordPress installation path
2. Scan your custom plugins and active theme
3. Show issues and offer to fix them

## CLI Only (without Claude)

```bash
git clone https://github.com/accentdesign/wp-doctor.git
cd wp-doctor
composer install

php bin/wp-doctor scan /path/to/wordpress/wp-content
```

### Options

```bash
# Non-interactive mode (for CI/scripts)
php bin/wp-doctor scan /path/to/wp-content --non-interactive

# JSON output for automation
php bin/wp-doctor scan /path/to/wp-content --format=json
```

## Plugin Classification

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

WP Doctor includes an MCP server that gives Claude direct access to scanning tools:

- `wp_doctor_scan` - Full health scan
- `wp_doctor_check_file` - Check a specific file
- `wp_doctor_preview_fixes` - Preview auto-fixes
- `wp_doctor_apply_fixes` - Apply fixes

Run `./wp-doctor-setup` once, then `claude` to start. Claude handles the rest.

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
- Node.js (for MCP server)
- Composer (for installation)
- [Claude Code](https://claude.ai/claude-code) (for AI-assisted workflow)

## License

MIT - see [LICENSE](LICENSE)

## Credits

Built by [Accent Design](https://accentdesign.co.uk)
