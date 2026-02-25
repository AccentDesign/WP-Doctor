#!/usr/bin/env node

/**
 * WP Doctor MCP Server
 *
 * Provides MCP tools for diagnosing WordPress installations.
 * Works in conjunction with the WP Doctor WP-CLI package.
 */

import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from "@modelcontextprotocol/sdk/types.js";
import { exec, execSync } from "child_process";
import { promisify } from "util";
import path from "path";
import fs from "fs";

const execAsync = promisify(exec);

// Get target path from command line arg or env
const targetPath = process.argv[2] || process.env.WP_DOCTOR_PATH || process.cwd();

// Configuration
const config = {
  wpPath: targetPath,
  wpDoctorBin: path.join(path.dirname(import.meta.url.replace('file://', '')), '..', 'bin', 'wp-doctor'),
  timeout: 120000, // 2 minutes
};

/**
 * Execute wp-doctor command using standalone script (no DB required)
 */
async function runWpDoctor(command, options = {}) {
  const wpPath = options.path || config.wpPath;

  // Map WP-CLI style commands to standalone script commands
  // WP-CLI: "scan --format=json" -> Standalone: "scan <path> --format=json"
  const standaloneCommand = `php "${config.wpDoctorBin}" ${command} "${wpPath}" --non-interactive 2>&1`;

  try {
    const { stdout, stderr } = await execAsync(standaloneCommand, {
      timeout: config.timeout,
      maxBuffer: 10 * 1024 * 1024,
      cwd: path.dirname(config.wpDoctorBin),
    });

    return {
      success: true,
      output: stdout,
      stderr: stderr || null,
    };
  } catch (error) {
    return {
      success: false,
      error: error.message,
      stderr: error.stderr || null,
      code: error.code,
    };
  }
}

/**
 * Check if WP-CLI and WP Doctor are available
 */
async function checkDependencies() {
  try {
    execSync(`${config.wpCliPath} --version`, { stdio: "pipe" });
    return { wpCli: true, wpDoctor: true };
  } catch {
    return { wpCli: false, wpDoctor: false };
  }
}

/**
 * Find WordPress installation path
 */
function findWordPressPath(startPath = process.cwd()) {
  let currentPath = startPath;

  while (currentPath !== "/") {
    const wpConfigPath = path.join(currentPath, "wp-config.php");
    if (fs.existsSync(wpConfigPath)) {
      return currentPath;
    }
    currentPath = path.dirname(currentPath);
  }

  return null;
}

// Create MCP Server
const server = new Server(
  {
    name: "wp-doctor-mcp",
    version: "1.0.0",
  },
  {
    capabilities: {
      tools: {},
    },
  }
);

// Define available tools
server.setRequestHandler(ListToolsRequestSchema, async () => {
  return {
    tools: [
      {
        name: "wp_doctor_scan",
        description:
          "Scan WordPress installation for code issues including deprecations, security vulnerabilities, performance problems, and coding standards violations. Returns a health score and detailed diagnostics.",
        inputSchema: {
          type: "object",
          properties: {
            path: {
              type: "string",
              description:
                "Path to WordPress installation. If not provided, will auto-detect.",
            },
            category: {
              type: "string",
              enum: [
                "all",
                "deprecations",
                "null-safety",
                "dependencies",
                "security",
                "performance",
                "hooks",
                "dead-code",
                "coding-standards",
              ],
              description: "Specific category to scan. Default: all",
            },
            plugins_only: {
              type: "boolean",
              description: "Scan plugins directory only",
            },
            themes_only: {
              type: "boolean",
              description: "Scan themes directory only",
            },
            active_only: {
              type: "boolean",
              description: "Only scan active plugins and current theme",
            },
          },
        },
      },
      {
        name: "wp_doctor_check_file",
        description:
          "Check a specific PHP file for WordPress code issues. Useful for checking individual files before committing.",
        inputSchema: {
          type: "object",
          properties: {
            file: {
              type: "string",
              description: "Path to the PHP file to check",
            },
            path: {
              type: "string",
              description: "Path to WordPress installation",
            },
          },
          required: ["file"],
        },
      },
      {
        name: "wp_doctor_explain",
        description:
          "Get detailed explanation of a specific diagnostic rule, including why it matters and how to fix it.",
        inputSchema: {
          type: "object",
          properties: {
            rule: {
              type: "string",
              description:
                "The rule identifier (e.g., 'deprecated-function', 'sql-injection', 'n-plus-one-query')",
            },
          },
          required: ["rule"],
        },
      },
    ],
  };
});

// Handle tool calls
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args } = request.params;

  // Auto-detect WordPress path if not provided
  const wpPath = args?.path || findWordPressPath() || config.wpPath;

  try {
    switch (name) {
      case "wp_doctor_scan": {
        let command = "scan --format=json";

        if (args?.category && args.category !== "all") {
          command += ` --category=${args.category}`;
        }
        if (args?.plugins_only) {
          command += " --plugins";
        }
        if (args?.themes_only) {
          command += " --themes";
        }
        if (args?.active_only) {
          command += " --active-only";
        }

        const result = await runWpDoctor(command, { path: wpPath });

        if (!result.success) {
          return {
            content: [
              {
                type: "text",
                text: `Error running scan: ${result.error}\n\nMake sure WP-CLI and WP Doctor are installed.`,
              },
            ],
          };
        }

        try {
          const scanResult = JSON.parse(result.output);
          return {
            content: [
              {
                type: "text",
                text: formatScanResult(scanResult),
              },
            ],
          };
        } catch {
          return {
            content: [
              {
                type: "text",
                text: result.output,
              },
            ],
          };
        }
      }

      case "wp_doctor_check_file": {
        if (!args?.file) {
          return {
            content: [
              {
                type: "text",
                text: "Error: file parameter is required",
              },
            ],
          };
        }

        const command = `check "${args.file}" --format=json`;
        // Note: standalone uses 'check', WP-CLI uses 'doctor check' (added by runWpDoctor)
        const result = await runWpDoctor(command, { path: wpPath });

        if (!result.success) {
          return {
            content: [
              {
                type: "text",
                text: `Error checking file: ${result.error}`,
              },
            ],
          };
        }

        return {
          content: [
            {
              type: "text",
              text: result.output,
            },
          ],
        };
      }

      case "wp_doctor_explain": {
        if (!args?.rule) {
          return {
            content: [
              {
                type: "text",
                text: "Error: rule parameter is required",
              },
            ],
          };
        }

        const explanation = getRuleExplanation(args.rule);
        return {
          content: [
            {
              type: "text",
              text: explanation,
            },
          ],
        };
      }

      default:
        return {
          content: [
            {
              type: "text",
              text: `Unknown tool: ${name}`,
            },
          ],
        };
    }
  } catch (error) {
    return {
      content: [
        {
          type: "text",
          text: `Error: ${error.message}`,
        },
      ],
    };
  }
});

/**
 * Format scan result for display
 */
function formatScanResult(result) {
  let output = [];

  // Header
  output.push("# WP Doctor Scan Results\n");

  // Score
  const score = result.score?.score ?? 0;
  const label = result.score?.label ?? "Unknown";
  output.push(`## Health Score: ${score}/100 (${label})\n`);

  // Stats
  if (result.stats) {
    output.push("### Statistics");
    output.push(`- Files scanned: ${result.stats.files_scanned}`);
    output.push(`- Total issues: ${result.stats.total_issues}`);
    output.push(`- Errors: ${result.stats.errors}`);
    output.push(`- Warnings: ${result.stats.warnings}`);
    output.push(`- Time: ${result.stats.elapsed_ms}ms\n`);
  }

  // Project info
  if (result.project) {
    output.push("### Project Info");
    output.push(`- WordPress: ${result.project.wordpress_version}`);
    output.push(`- PHP: ${result.project.php_version}`);
    output.push(`- Theme: ${result.project.active_theme}`);
    output.push(`- Plugins: ${result.project.plugins_count}\n`);
  }

  // Diagnostics grouped by category
  if (result.diagnostics && result.diagnostics.length > 0) {
    const byCategory = {};
    for (const d of result.diagnostics) {
      const cat = d.category || "unknown";
      if (!byCategory[cat]) {
        byCategory[cat] = [];
      }
      byCategory[cat].push(d);
    }

    for (const [category, diagnostics] of Object.entries(byCategory)) {
      output.push(`### ${category.charAt(0).toUpperCase() + category.slice(1)} (${diagnostics.length})`);

      for (const d of diagnostics.slice(0, 10)) {
        // Limit to 10 per category
        const icon = d.severity === "error" ? "❌" : "⚠️";
        output.push(`${icon} **${d.rule}**: ${d.message}`);
        output.push(`   ${d.file}:${d.line}`);
        if (d.help) {
          output.push(`   → ${d.help}`);
        }
      }

      if (diagnostics.length > 10) {
        output.push(`   ... and ${diagnostics.length - 10} more`);
      }
      output.push("");
    }
  } else {
    output.push("✅ No issues found!");
  }

  return output.join("\n");
}

/**
 * Get explanation for a diagnostic rule
 */
function getRuleExplanation(rule) {
  const explanations = {
    "deprecated-function": `
# Deprecated Function

## What it means
A function that has been deprecated in WordPress or PHP is being used. Deprecated functions may be removed in future versions.

## Why it matters
- Your code may break when WordPress or PHP is updated
- Deprecated functions often have better alternatives
- Using deprecated code signals technical debt

## How to fix
Replace the deprecated function with its recommended alternative. The diagnostic message includes the suggested replacement.

## Example
\`\`\`php
// Before (deprecated)
get_currentuserinfo();

// After
wp_get_current_user();
\`\`\`
`,

    "sql-injection": `
# SQL Injection Vulnerability

## What it means
User input is being used directly in SQL queries without proper sanitization or parameterization.

## Why it matters
- Attackers can execute arbitrary SQL commands
- Database can be compromised or destroyed
- Sensitive data can be stolen
- This is one of the most critical security vulnerabilities

## How to fix
Always use $wpdb->prepare() for queries with variables:

\`\`\`php
// UNSAFE
$wpdb->query("SELECT * FROM users WHERE id = $user_id");

// SAFE
$wpdb->query($wpdb->prepare(
    "SELECT * FROM users WHERE id = %d",
    $user_id
));
\`\`\`
`,

    "n-plus-one-query": `
# N+1 Query Problem

## What it means
A database query is being executed inside a loop, resulting in N+1 queries instead of a single efficient query.

## Why it matters
- Causes severe performance degradation
- Database becomes bottleneck
- Page load times increase dramatically with more data
- Server resources are wasted

## How to fix
Fetch all needed data before the loop:

\`\`\`php
// BEFORE (N+1 queries)
foreach ($posts as $post) {
    $meta = get_post_meta($post->ID, 'custom_field', true);
}

// AFTER (1 query + caching)
update_meta_cache('post', wp_list_pluck($posts, 'ID'));
foreach ($posts as $post) {
    $meta = get_post_meta($post->ID, 'custom_field', true);
}
\`\`\`
`,

    "missing-nonce": `
# Missing Nonce Verification

## What it means
Form data ($_POST, $_GET, $_REQUEST) is being processed without verifying a nonce (number used once) token.

## Why it matters
- Vulnerable to Cross-Site Request Forgery (CSRF)
- Attackers can trick users into performing actions
- User data can be modified without consent

## How to fix
Add nonce to forms and verify before processing:

\`\`\`php
// In your form
wp_nonce_field('my_action', 'my_nonce');

// When processing
if (!wp_verify_nonce($_POST['my_nonce'], 'my_action')) {
    wp_die('Security check failed');
}
\`\`\`
`,

    "query-posts": `
# query_posts() Usage

## What it means
The query_posts() function is being used to modify the WordPress query.

## Why it matters
- Modifies the main query which causes many issues
- Breaks pagination
- Requires wp_reset_query() which is often forgotten
- Slower than alternatives

## How to fix
Use WP_Query or pre_get_posts hook instead:

\`\`\`php
// BEFORE
query_posts(['cat' => 5]);
while (have_posts()) { ... }

// AFTER
$query = new WP_Query(['cat' => 5]);
while ($query->have_posts()) {
    $query->the_post();
    ...
}
wp_reset_postdata();
\`\`\`
`,
  };

  return (
    explanations[rule] ||
    `
# ${rule}

No detailed explanation available for this rule.

Please refer to WordPress coding standards and best practices documentation.
`
  );
}

// Start the server
async function main() {
  const deps = await checkDependencies();

  if (!deps.wpCli) {
    console.error("Warning: WP-CLI not found. Some features may not work.");
  }

  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error("WP Doctor MCP Server started");
}

main().catch((error) => {
  console.error("Fatal error:", error);
  process.exit(1);
});
