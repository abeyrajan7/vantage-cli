#!/usr/bin/env node
// Simple Playwright fetcher helper
// Usage: node tools/fetcher.js --url "https://..." --wait-for 2000

import { chromium } from "playwright";

function parseArgs() {
    const args = process.argv.slice(2);
    const out = {};
    for (let i = 0; i < args.length; i++) {
        const a = args[i];
        if (a === "--url" && args[i + 1]) {
            out.url = args[++i];
        } else if (a === "--wait-for" && args[i + 1]) {
            out.waitFor = parseInt(args[++i], 10);
        } else if (a === "--user-agent" && args[i + 1]) {
            out.ua = args[++i];
        } else if (a === "--cookies" && args[i + 1]) {
            out.cookiesFile = args[++i];
        }
    }
    return out;
}

(async () => {
    const opts = parseArgs();
    if (!opts.url) {
        console.error(
            'Usage: node tools/fetcher.js --url <url> [--wait-for ms] [--user-agent "UA"] [--cookies <file.json>]'
        );
        process.exit(2);
    }

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        userAgent: opts.ua || undefined,
    });

    // Load cookies if provided (expects JSON map {name: value})
    if (opts.cookiesFile) {
        try {
            const fs = await import("fs");
            const content = fs.readFileSync(opts.cookiesFile, "utf8");
            const json = JSON.parse(content);
            const cookies = [];
            for (const [name, value] of Object.entries(json)) {
                cookies.push({
                    name,
                    value: String(value),
                    domain: "www.cochranelibrary.com",
                    path: "/",
                });
            }
            await context.addCookies(cookies);
        } catch (e) {
            console.error("Failed to load cookies:", e.message);
        }
    }

    const page = await context.newPage();
    try {
        const resp = await page.goto(opts.url, { waitUntil: "networkidle" });
        if (opts.waitFor) await page.waitForTimeout(opts.waitFor);
        const html = await page.content();
        console.log(html);
    } catch (e) {
        console.error("Fetch failed:", e.message);
        process.exit(3);
    } finally {
        await browser.close();
    }
})();
