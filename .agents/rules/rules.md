---
paths:
  - '.agents/rules/**'
---

# Project Rule Best Practices

## Follow the rule format
Use an imperative heading followed by one concise rule statement.

## Keep Rules Concise
State each rule clearly and specifically in one sentence unless a second sentence is required to prevent ambiguity; omit background, explanations, examples, and repeated conclusions.

## Record project conventions
Record PokerX-specific behavior not already covered by `AGENTS.md`, Hyperf's official documentation, or another rule file.

## Record Only Necessary Rules
Avoid imposing behavior for absent modules as though they already exist; mark future-feature guidance as conditional.

## Make Rules Decidable
Write each rule so an agent can check compliance against affected files, tests, or configuration.

## Write project rules in English
Write rule headings and statements in English to match the existing rule files.

## Keep the index and frontmatter aligned
When adding or moving a rule, update `.agents/rules/index.md` and its `paths` frontmatter to match actual repository paths.
