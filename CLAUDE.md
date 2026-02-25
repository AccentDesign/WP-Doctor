# WP Doctor - Claude Instructions

## On First Message (any message)

Whatever the user says first, do this:

1. Check if `.mcp.json` exists in this directory
2. If NO → say "Run `./wp-doctor-setup` first, then come back."
3. If YES → ask "What's the path to your WordPress installation?"

Once you have a path, use `wp_doctor_scan` to analyze it and show the results.

## MCP Tools

- `wp_doctor_scan` - Full health scan (returns issues to fix)
- `wp_doctor_check_file` - Check a specific file
- `wp_doctor_explain` - Explain a rule

## Fixing Issues

After scanning, fix issues directly using the Edit tool. No auto-fixer needed - you read the diagnostics, understand the context, and make the appropriate fix.
