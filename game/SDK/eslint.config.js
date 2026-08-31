import eslint from "@eslint/js";
import { defineConfig } from "eslint/config";
import tseslint from "typescript-eslint";

export default defineConfig(
  {
    ignores: [
      "artifacts/**",
      "cache/**",
      "coverage/**",
      "dist/**",
      "node_modules/**",
    ],
  },
  eslint.configs.recommended,
  tseslint.configs.recommended,
  {
    files: ["**/*.js", "**/*.ts"],
    rules: {
      "no-console": "off",
    },
  },
);
