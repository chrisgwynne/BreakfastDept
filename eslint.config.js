// ESLint flat config (ESLint v9+).
module.exports = [
  {
    files: ["public/assets/js/**/*.js", "site/plugins/breakfast-platform/index.js"],
    languageOptions: {
      ecmaVersion: 2021,
      sourceType: "script",
      globals: { window: "readonly", document: "readonly", localStorage: "readonly", panel: "readonly", Intl: "readonly", CustomEvent: "readonly", IntersectionObserver: "readonly", fetch: "readonly", FormData: "readonly", navigator: "readonly" },
    },
    rules: {
      "no-unused-vars": ["warn", { args: "none" }],
      "no-empty": ["error", { allowEmptyCatch: true }],
      "no-undef": "error",
    },
  },
  { ignores: ["vendor/**", "kirby/**", "public/media/**", "node_modules/**"] },
];
