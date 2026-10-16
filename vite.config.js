import { defineConfig } from "vite";
import { resolve } from "node:path";

export default defineConfig({
  root: resolve("public"),
  appType: "mpa",
  build: {
    outDir: resolve("dist"),
    emptyOutDir: true,
  },
  server: {
    host: "127.0.0.1",
    port: 8080,
    strictPort: true,
  },
  preview: {
    host: "127.0.0.1",
    port: 4173,
    strictPort: true,
  },
});