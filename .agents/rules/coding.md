---
paths:
  - '**/*.php'
---

# Coding

## Use simple, precise project names
Name tables, columns, classes, methods, routes, parameters, and variables with familiar domain words; avoid unexplained abbreviations and redundant qualifiers while following the existing `Controller`, `Request`, `Service`, `Job`, `Vo`, and `Enum` suffixes.

## Follow the current PHP style
Use strict types, typed parameters and return values, braces for control flow, and PHPDoc array shapes for complex arrays; follow neighboring files and run Pint only on files changed for the task.

## Implement only what is necessary
Before adding a file, class, method, function, variable, condition, state, or dependency, check whether confirmed behavior still works without it; omit speculative abstractions.

## Keep code changes within the requested scope
Limit code changes to the explicitly requested objective; do not introduce extra designs or alter the framework's architecture, and obtain confirmation before any necessary exception.

## Keep responsibilities and layers clear
Give each class and method one clear responsibility, place behavior in the layer that owns it, and make calls between layers easy to follow.

## Prioritize human readability
Prefer straightforward data flow and control flow that a maintainer can understand on first read over cleverness, compactness, or premature generality.

## Explain critical constraints in Chinese
Add concise Chinese comments for non-obvious protocol, concurrency, lifecycle, and recovery constraints; do not comment on self-explanatory code.
