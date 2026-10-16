import { build } from "esbuild";
import { cp, mkdir, readdir, rm } from "node:fs/promises";
import { join, relative, resolve } from "node:path";

const projectRoot = resolve(".");
const publicRoot = resolve("public");
const outputRoot = resolve("dist");
const excludedFiles = new Set([
  "clients.html",
  "invoices.html",
  "transactions.html",
  "invoice-create.html",
  "js/api.js",
  "js/app.js",
  "js/client.js",
  "js/dashboard.js",
  "js/invoices.js",
  "js/transactions.js",
  "js/ui.js",
]);

async function copyStatic(source, destination) {
  await mkdir(destination, { recursive: true });
  for (const entry of await readdir(source, { withFileTypes: true })) {
    const from = join(source, entry.name);
    const to = join(destination, entry.name);
    const relativePath = relative(publicRoot, from).replaceAll("\\", "/");
    if (excludedFiles.has(relativePath) || entry.name.endsWith(".php")) continue;
    if (relativePath === "js") {
      await mkdir(to, { recursive: true });
      await cp(join(from, "config.js"), join(to, "config.js"));
      continue;
    }
    if (entry.isDirectory()) await copyStatic(from, to);
    else await cp(from, to);
  }
}

await rm(outputRoot, { recursive: true, force: true });
await copyStatic(publicRoot, outputRoot);
await cp(resolve(projectRoot, "favicon"), join(outputRoot, "favicon"), { recursive: true });
await build({
  entryPoints: {
    login: resolve(publicRoot, "js/login.js"),
    workspace: resolve(publicRoot, "js/workspace.js"),
    vendor: resolve(publicRoot, "js/vendor.css"),
  },
  bundle: true,
  format: "esm",
  platform: "browser",
  target: "es2022",
  outdir: join(outputRoot, "js"),
  assetNames: "assets/[name]-[hash]",
  loader: {
    ".woff": "file",
    ".woff2": "file",
  },
  packages: "bundle",
  minify: true,
});

console.log("Built static Supabase app in dist/; PHP endpoints and PHP-only scripts were excluded.");